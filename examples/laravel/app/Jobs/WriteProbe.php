<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

final class WriteProbe implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $value) {}

    public function handle(): void
    {
        Cache::put('probe', $this->value, 600);
    }
}
