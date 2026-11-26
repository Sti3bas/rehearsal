<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Broadcasting\BroadcastManager;
use RuntimeException;

final class Reverb implements Integration, ClientMetadata
{
    public function provision(Run $run): void
    {
        LocalService::origin(config('rehearsal.reverb.origin'));
    }

    public function bind(Attempt $attempt): void
    {
        $app = $this->credentials($attempt);
        $options = $this->clientMetadata($attempt);
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'app_id' => $app['id'],
                'key' => $app['key'],
                'secret' => $app['secret'],
                'options' => ['host' => $options['host'], 'port' => $options['port'], 'scheme' => 'http', 'useTLS' => false],
                'client_options' => ['timeout' => 5, 'allow_redirects' => false],
            ],
        ]);
        app(BroadcastManager::class)->purge('reverb');
    }

    public function reset(Attempt $attempt): void
    {
        $path = $attempt->path('reverb.json');
        $file = fopen($path.'.next', 'wb');
        if ($file === false) {
            throw new RuntimeException('Could not write Rehearsal Reverb credentials.');
        }
        try {
            $app = json_encode([
                'attempt' => $attempt->id,
                'id' => 'rh-'.$attempt->run->id.'-'.$attempt->slot.'-'.$attempt->id,
                'key' => bin2hex(random_bytes(16)),
                'secret' => bin2hex(random_bytes(32)),
            ], JSON_THROW_ON_ERROR);
            if (! chmod($path.'.next', 0600) || fwrite($file, $app) !== strlen($app)) {
                throw new RuntimeException('Could not write Rehearsal Reverb credentials.');
            }
        } finally {
            fclose($file);
        }
        if (! rename($path.'.next', $path)) {
            throw new RuntimeException('Could not publish Rehearsal Reverb credentials.');
        }
    }

    public function cleanup(Run $run): void
    {
        foreach (glob($run->path('slot-*-reverb.json')) ?: [] as $path) {
            if (! unlink($path)) {
                throw new RuntimeException('Could not remove Rehearsal Reverb credentials.');
            }
        }
    }

    public function clientMetadata(Attempt $attempt): array
    {
        $origin = LocalService::origin(config('rehearsal.reverb.origin'));

        return ['key' => $this->credentials($attempt)['key'], 'host' => $origin['host'], 'port' => $origin['port'] ?? 80, 'scheme' => 'ws'];
    }

    private function credentials(Attempt $attempt): array
    {
        $app = json_decode(file_get_contents($attempt->path('reverb.json')), true, flags: JSON_THROW_ON_ERROR);
        if ($app['attempt'] !== $attempt->id) {
            throw new RuntimeException('Rehearsal Reverb credentials do not belong to this attempt.');
        }

        return $app;
    }
}
