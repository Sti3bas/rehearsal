<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Support\Facades\Date;
use RuntimeException;

final class Clock implements Integration
{
    public function provision(Run $run): void {}

    public function bind(Attempt $attempt): void
    {
        $path = $attempt->path('clock');
        Date::setTestNow(is_file($path) ? file_get_contents($path) : null);
    }

    public static function store(Attempt $attempt, string $value): void
    {
        $path = $attempt->path('clock');
        $temp = $path.'.'.bin2hex(random_bytes(8));
        if (file_put_contents($temp, $value) === false || ! rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Could not persist Rehearsal clock.');
        }
    }

    public function reset(Attempt $attempt): void
    {
        $path = $attempt->path('clock');
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException('Could not reset Rehearsal clock.');
        }
    }

    public function cleanup(Run $run): void {}
}
