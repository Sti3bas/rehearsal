<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

final class LaravelState implements Integration
{
    public function provision(Run $run): void {}

    public function bind(Attempt $attempt): void
    {
        config([
            'session.driver' => 'database',
            'session.connection' => 'rehearsal',
            'session.cookie' => 'rehearsal_'.$attempt->run->id.'_'.$attempt->slot,
            'cache.default' => 'database',
            'cache.stores.database.connection' => 'rehearsal',
            'cache.stores.database.lock_connection' => 'rehearsal',
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'rehearsal',
            'queue.failed.driver' => 'database-uuids',
            'queue.failed.database' => 'rehearsal',
            'queue.batching.database' => 'rehearsal',
        ]);
        Cache::forgetDriver('database');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();
        foreach (['queue', 'queue.failer', 'queue.batch.repository'] as $binding) {
            app()->forgetInstance($binding);
            Facade::clearResolvedInstance($binding);
        }
    }

    public function reset(Attempt $attempt): void {}

    public function cleanup(Run $run): void {}
}
