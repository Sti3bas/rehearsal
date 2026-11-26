<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class Filesystem implements Integration
{
    public function provision(Run $run): void {}

    public function bind(Attempt $attempt): void
    {
        foreach (config('rehearsal.disks') as $disk) {
            $root = $attempt->path('disk-'.$disk);
            config(['filesystems.disks.'.$disk => ['driver' => 'local', 'root' => $root, 'throw' => true]]);
            Storage::forgetDisk($disk);
        }
    }

    public function reset(Attempt $attempt): void
    {
        foreach (config('rehearsal.disks') as $disk) {
            if (! is_string($disk) || ! preg_match('/\A[a-zA-Z0-9_-]+\z/', $disk)) {
                throw new \RuntimeException('Invalid Rehearsal disk alias.');
            }
            $path = $attempt->path('disk-'.$disk);
            if (is_dir($path) && ! File::deleteDirectory($path)) {
                throw new \RuntimeException('Could not reset a Rehearsal disk.');
            }
        }
    }

    public function cleanup(Run $run): void {}
}
