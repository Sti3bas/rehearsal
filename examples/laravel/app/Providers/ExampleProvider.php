<?php

namespace App\Providers;

use App\Jobs\WriteProbe;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Sti3bas\Rehearsal\Operations;

final class ExampleProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->environment('playwright')) {
            return;
        }

        $this->app->make(Operations::class)->register('probe.write', [
            'value' => 'required|string|max:100',
        ], function (array $data): void {
            Storage::disk('local')->put('probe.txt', $data['value']);
            Bus::dispatch(new WriteProbe($data['value']));
        });
    }
}
