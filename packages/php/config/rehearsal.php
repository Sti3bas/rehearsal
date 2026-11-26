<?php

use Sti3bas\Rehearsal\Clock;
use Sti3bas\Rehearsal\Database;
use Sti3bas\Rehearsal\Filesystem;
use Sti3bas\Rehearsal\LaravelState;

return [
    'enabled' => env('REHEARSAL_ENABLED', false),
    'run' => env('REHEARSAL_RUN'),
    'capability' => env('REHEARSAL_CAPABILITY'),
    'integrations' => [
        'database' => Database::class,
        'filesystem' => Filesystem::class,
        'laravel-state' => LaravelState::class,
        'clock' => Clock::class,
    ],
    'database' => [
        'driver' => env('DB_CONNECTION', 'sqlite'),
        'disposable_server' => env('REHEARSAL_DB_DISPOSABLE', false),
        'aliases' => [],
        'seeder' => null,
    ],
    'disks' => ['local', 'public'],
    'factories' => [
        'User' => ['model' => App\Models\User::class, 'states' => []],
    ],
    'artisan' => [],
    'login' => null,
];
