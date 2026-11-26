<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class Run
{
    public const MAX_SLOTS = 64;

    public function __construct(public string $id)
    {
        if (! preg_match('/\A[a-f0-9]{24}\z/', $id)) {
            throw new RuntimeException('Invalid Rehearsal run identity.');
        }
    }

    public function path(string $file = ''): string
    {
        return base_path('.rehearsal/runs/'.$this->id.($file !== '' ? '/'.$file : ''));
    }

    public function ensureDirectory(): void
    {
        (new Filesystem)->ensureDirectoryExists($this->path(), 0700, true);
    }

    public function acquire(string $name, int $mode): mixed
    {
        $file = fopen($this->path($name.'.lock'), 'c');
        if ($file === false) {
            throw new RuntimeException('Cannot lock Rehearsal state.');
        }

        $deadline = microtime(true) + 30;
        try {
            while (! flock($file, $mode | LOCK_NB)) {
                abort_if(microtime(true) >= $deadline, 503, 'Rehearsal is waiting for outstanding requests.');
                usleep(20_000);
            }

            return $file;
        } catch (\Throwable $error) {
            fclose($file);
            throw $error;
        }
    }

    public function lock(string $name, int $mode, callable $action): mixed
    {
        $file = $this->acquire($name, $mode);
        try {
            return $action();
        } finally {
            fclose($file);
        }
    }
}
