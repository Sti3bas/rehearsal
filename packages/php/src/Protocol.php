<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Http\Request;
use RuntimeException;

final readonly class Protocol
{
    public const VERSION = 2;
    public const MARKER = 'X-Rehearsal-Attempt';
    public const MARKER_COOKIE = 'rehearsal_attempt';
    public const CAPABILITY = 'X-Rehearsal-Capability';

    public function __construct(public Run $run, private Integrations $integrations) {}

    public function authorize(Request $request): void
    {
        abort_unless(hash_equals(config('rehearsal.capability'), $request->header(self::CAPABILITY, '')), 401);
    }

    public function provision(): void
    {
        $this->run->ensureDirectory();
        $this->run->lock('provision', LOCK_EX, function (): void {
            if (is_file($this->run->path('ready'))) {
                return;
            }

            foreach ($this->integrations->all() as $integration) {
                $integration->provision($this->run);
            }

            $this->write(Factories::SNAPSHOT, json_encode(app(Factories::class)->discover(), JSON_THROW_ON_ERROR));
            $this->write('ready', (string) self::VERSION);
        });
    }

    public function begin(int $slot): array
    {
        abort_unless($slot >= 1 && $slot <= Run::MAX_SLOTS, 422);
        abort_unless(is_file($this->run->path('ready')), 503);
        $attempt = new Attempt($this->run, $slot, bin2hex(random_bytes(16)));

        return $this->run->lock('slot-'.$slot, LOCK_EX, function () use ($attempt): array {
            $this->write('slot-'.$attempt->slot.'.attempt', '');
            $timings = [];
            $client = [];
            foreach ($this->integrations->all() as $name => $integration) {
                $start = hrtime(true);
                $integration->reset($attempt);
                $timings[$name] = round((hrtime(true) - $start) / 1e6, 2);
                if ($integration instanceof ClientMetadata) {
                    $client[$name] = $integration->clientMetadata($attempt);
                }
            }

            $this->write('slot-'.$attempt->slot.'.attempt', $attempt->id);
            return ['marker' => $this->marker($attempt), 'resetMs' => $timings, 'client' => $client];
        });
    }

    public function marker(Attempt $attempt): string
    {
        $payload = implode('.', [$attempt->run->id, $attempt->slot, $attempt->id, time() + 7200]);

        return $payload.'.'.$this->sign($payload);
    }

    public function resolve(string $marker): Attempt
    {
        abort_unless(preg_match('/\A([a-f0-9]{24})\.([1-9][0-9]?)\.([a-f0-9]{32})\.([0-9]{10})\.([a-f0-9]{64})\z/', $marker, $matches), 403);
        [, $run, $slot, $attempt, $expiry, $provided] = $matches;
        $payload = implode('.', [$run, $slot, $attempt, $expiry]);
        abort_unless($run === $this->run->id && (int) $slot <= Run::MAX_SLOTS && (int) $expiry >= time()
            && hash_equals($this->sign($payload), $provided), 403);

        return new Attempt($this->run, (int) $slot, $attempt);
    }

    public function assertCurrent(Attempt $attempt): void
    {
        $state = $this->run->path('slot-'.$attempt->slot.'.attempt');
        abort_unless(@file_get_contents($state) === $attempt->id, 409, 'Rehearsal attempt is no longer active.');
    }

    public function cleanup(): void
    {
        foreach (array_reverse($this->integrations->all()) as $integration) {
            $integration->cleanup($this->run);
        }
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', 'rehearsal-attempt:'.$payload, config('rehearsal.capability'));
    }

    private function write(string $name, string $value): void
    {
        if (file_put_contents($this->run->path($name), $value, LOCK_EX) === false) {
            throw new RuntimeException('Could not persist Rehearsal state.');
        }
    }
}
