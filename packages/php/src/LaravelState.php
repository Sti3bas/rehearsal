<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;

final class LaravelState implements Integration
{
    private const LIMITER_STORE = 'rehearsal-limiter';

    public static function configureRateLimiter(Application $app): void
    {
        if (config('cache.limiter') !== null) {
            return;
        }

        config([
            'cache.limiter' => self::LIMITER_STORE,
            'cache.stores.'.self::LIMITER_STORE => ['driver' => self::LIMITER_STORE],
        ]);

        $store = self::LIMITER_STORE;
        $register = static function (CacheManager $cache) use ($app, $store): void {
            $cache->extend($store, static function () use ($app, $cache, $store): Repository {
                $repository = $cache->repository($cache->store()->getStore());
                $app->instance($store, $repository);

                return $repository;
            });
        };
        $app->afterResolving('cache', $register);
        if ($app->resolved('cache')) {
            $register($app->make('cache'));
        }
    }

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
        if (app()->bound(self::LIMITER_STORE)) {
            app(self::LIMITER_STORE)->setStore(Cache::store('database')->getStore());
        }
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
