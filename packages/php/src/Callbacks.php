<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use LogicException;
use RuntimeException;

final readonly class Callbacks
{
    public function __construct(private Container $container, private Protocol $protocol) {}

    public static function configured(string $path): bool
    {
        return $path !== '/_rehearsal' && ! str_starts_with($path, '/_rehearsal/')
            && isset(config('rehearsal.callbacks')[$path]);
    }

    public function bind(string $path, string $reference): void
    {
        if (! self::configured($path) || ! $this->container->bound(Attempt::class)) {
            throw new LogicException('Rehearsal callbacks require a configured path and a bound attempt.');
        }
        $attempt = $this->container->make(Attempt::class);
        $this->protocol->assertCurrent($attempt);
        $key = $this->key($path, $reference);
        $run = $attempt->run;

        $run->lock($key, LOCK_EX, function () use ($run, $key, $attempt): void {
            $file = $run->path($key.'.marker');
            if (is_file($file)) {
                $bound = $this->protocol->resolve(file_get_contents($file));
                if ($bound->id !== $attempt->id || $bound->slot !== $attempt->slot) {
                    throw new LogicException('This callback reference already belongs to another Rehearsal attempt.');
                }

                return;
            }

            $marker = $this->protocol->marker($attempt);
            if (file_put_contents($file, $marker) !== strlen($marker)) {
                throw new RuntimeException('Could not persist Rehearsal callback binding.');
            }
        });
    }

    public function resolve(Request $request): ?Attempt
    {
        abort_unless(in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true), 403);
        $path = $request->getPathInfo();
        $resolver = $this->container->make(config('rehearsal.callbacks')[$path]);
        if (! $resolver instanceof CallbackReference) {
            throw new LogicException('A Rehearsal callback resolver must implement CallbackReference.');
        }
        $reference = $resolver->verifiedReference($request);
        if ($reference === null) {
            return null;
        }

        $run = $this->protocol->run;
        $key = $this->key($path, $reference);
        if (! is_file($run->path($key.'.marker'))) {
            return null;
        }
        $marker = $run->lock($key, LOCK_SH, static fn () => file_get_contents($run->path($key.'.marker')));

        return $this->protocol->resolve($marker);
    }

    private function key(string $path, string $reference): string
    {
        abort_if($reference === '' || strlen($reference) > 255, 422);

        return 'callback-'.hash('sha256', $path."\0".$reference);
    }
}
