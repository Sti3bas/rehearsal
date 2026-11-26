<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class Filesystem implements Integration
{
    public static function configureDisks(Run $run): void
    {
        foreach (self::disks() as $disk) {
            $existing = config('filesystems.disks.'.$disk, []);
            $public = ($existing['visibility'] ?? null) === 'public' || $disk === 'public';
            config(['filesystems.disks.'.$disk => [
                'driver' => 'local',
                'root' => $run->path('unbound-disk-'.$disk),
                'throw' => true,
                'serve' => $public || ($existing['serve'] ?? false),
                'visibility' => $public ? 'public' : 'private',
                'url' => rtrim(config('app.url'), '/').'/_rehearsal/files/'.$disk,
            ]]);
            Storage::forgetDisk($disk);
        }
    }

    public function provision(Run $run): void {}

    public function bind(Attempt $attempt): void
    {
        foreach (self::disks() as $disk) {
            config(['filesystems.disks.'.$disk.'.root' => $attempt->path('disk-'.$disk)]);
            Storage::forgetDisk($disk);
        }
        app()->forgetInstance('filesystem.disk');
        app()->forgetInstance('filesystem.cloud');
    }

    public function reset(Attempt $attempt): void
    {
        foreach (self::disks() as $disk) {
            $path = $attempt->path('disk-'.$disk);
            if (is_dir($path) && ! File::deleteDirectory($path)) {
                throw new \RuntimeException('Could not reset a Rehearsal disk.');
            }
        }
    }

    public function cleanup(Run $run): void {}

    private static function disks(): array
    {
        $disks = config('rehearsal.disks');
        foreach ($disks as $disk) {
            if (! is_string($disk) || ! preg_match('/\A[a-zA-Z0-9_-]+\z/', $disk)) {
                throw new \RuntimeException('Invalid Rehearsal disk alias.');
            }
        }

        return $disks;
    }
}
