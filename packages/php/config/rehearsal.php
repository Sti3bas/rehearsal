<?php

use Sti3bas\Rehearsal\Clock;
use Sti3bas\Rehearsal\Database;
use Sti3bas\Rehearsal\Filesystem;
use Sti3bas\Rehearsal\LaravelState;

return [
    'enabled' => env('REHEARSAL_ENABLED', false),
    'environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('REHEARSAL_ENVIRONMENTS', 'testing,rehearsal'))))),
    'run' => env('REHEARSAL_RUN'),
    'capability' => env('REHEARSAL_CAPABILITY'),
    'safe_defaults' => [],
    'integrations' => [
        'database' => Database::class,
        'filesystem' => Filesystem::class,
        'laravel-state' => LaravelState::class,
        'clock' => Clock::class,
    ],
    'database' => [
        'driver' => env('DB_CONNECTION', 'sqlite'),
        'sqlite_transaction_mode' => 'DEFERRED',
        'disposable_server' => env('REHEARSAL_DB_DISPOSABLE', false),
        'aliases' => [],
        'seeder' => null,
    ],
    'disks' => ['local', 'public'],
    'factories' => ['discover' => true],
    'operations' => ['discover' => true],
    'artisan' => [],
    'schedule' => ['discover' => true],
    'fault_paths' => [],
    'route_mocks' => true,
    'callbacks' => [],
    'login' => null,
    'mailpit' => ['api' => null, 'smtp_port' => 1025],
    'scout' => ['engine' => null, 'origin' => null, 'admin_key' => null, 'models' => [], 'baseline_models' => []],
    'reverb' => ['origin' => null, 'server' => env('REHEARSAL_REVERB_SERVER', false)],
];
