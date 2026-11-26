<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final class Mailpit implements Integration
{
    public function provision(Run $run): void
    {
        $this->api()->get('/api/v1/search', ['query' => 'tag:rehearsal-'.$run->id])->throw();
    }

    public function bind(Attempt $attempt): void
    {
        $origin = LocalService::origin(config('rehearsal.mailpit.api'));
        $port = config('rehearsal.mailpit.smtp_port', 1025);
        if (! is_int($port) || $port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid Rehearsal Mailpit SMTP port.');
        }
        config([
            'mail.default' => 'rehearsal-mailpit',
            'mail.mailers.rehearsal-mailpit' => [
                'transport' => 'smtp', 'scheme' => 'smtp', 'host' => $origin['host'],
                'port' => $port, 'timeout' => 5,
            ],
        ]);
        Mail::purge('rehearsal-mailpit');
        Event::listen(MessageSending::class, static function (MessageSending $event) use ($attempt): void {
            $headers = $event->message->getHeaders();
            $headers->remove('X-Tags');
            $headers->addTextHeader('X-Tags', self::tag($attempt));
        });
    }

    public function reset(Attempt $attempt): void
    {
        $path = $attempt->path('mailpit');
        if (is_file($path)) {
            $this->clear($attempt->run, trim(file_get_contents($path)));
        }
        if (file_put_contents($path, self::tag($attempt), LOCK_EX) === false) {
            throw new RuntimeException('Could not record the Rehearsal Mailpit tag.');
        }
    }

    public function cleanup(Run $run): void
    {
        foreach (glob($run->path('slot-*-mailpit')) ?: [] as $path) {
            $this->clear($run, trim(file_get_contents($path)));
        }
    }

    public function messages(Attempt $attempt): array
    {
        return $this->search(self::tag($attempt));
    }

    private static function tag(Attempt $attempt): string
    {
        return "rehearsal-{$attempt->run->id}-{$attempt->slot}-{$attempt->id}";
    }

    private function api(): PendingRequest
    {
        $origin = config('rehearsal.mailpit.api');
        LocalService::origin($origin);

        return Http::baseUrl($origin)->acceptJson()->withoutRedirecting()->timeout(5);
    }

    private function search(string $tag): array
    {
        $messages = $this->api()->get('/api/v1/search', ['query' => 'tag:'.$tag, 'limit' => 100])
            ->throw()->json('messages');

        return array_values(array_filter($messages, static fn (array $msg) => in_array($tag, $msg['Tags'] ?? [], true)));
    }

    private function clear(Run $run, string $tag): void
    {
        if (! preg_match('/\Arehearsal-'.$run->id.'-[1-9][0-9]?-[a-f0-9]{32}\z/', $tag)) {
            throw new RuntimeException('Rehearsal refused to delete a Mailpit tag outside this run.');
        }
        while ($ids = array_column($this->search($tag), 'ID')) {
            $this->api()->delete('/api/v1/messages', ['IDs' => $ids])->throw();
        }
    }
}
