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
        'discover' => true,
        'database' => Database::class,
        'filesystem' => Filesystem::class,
        'laravel-state' => LaravelState::class,
        'clock' => Clock::class,
    ],
    'database' => [
        'driver' => env('REHEARSAL_DB_DRIVER', env('DB_CONNECTION', 'sqlite')),
        'sqlite_transaction_mode' => env('REHEARSAL_SQLITE_TRANSACTION_MODE', 'DEFERRED'),
        'disposable_server' => env('REHEARSAL_DB_DISPOSABLE', false),
        'aliases' => [],
        'seeder' => env('REHEARSAL_DB_SEEDER'),
    ],
    'disks' => ['local', 'public'],
    'factories' => ['discover' => true],
    'operations' => ['discover' => true],
    'artisan' => ['discover' => true],
    'schedule' => ['discover' => true],
    'fake_routes' => ['discover' => true],
    'fake_response_types' => [],
    'route_mocks' => true,
    'callbacks' => ['discover' => true],
    'login' => true,
    'mailpit' => [
        'api' => env('REHEARSAL_MAILPIT_API', env('MAILPIT_URL')),
        'smtp_port' => (int) env('REHEARSAL_MAILPIT_SMTP_PORT', env('MAIL_PORT', 1025)),
    ],
    'scout' => [
        'engine' => env('REHEARSAL_SCOUT_ENGINE', env('SCOUT_DRIVER')),
        'origin' => env('REHEARSAL_SCOUT_ORIGIN'),
        'admin_key' => env('REHEARSAL_SCOUT_ADMIN_KEY'),
        'models' => ['discover' => true],
        'baseline_models' => array_values(array_filter(array_map('trim', explode(',', (string) env('REHEARSAL_SCOUT_BASELINE_MODELS', ''))))),
    ],
    'reverb' => [
        'origin' => env('REHEARSAL_REVERB_ORIGIN', env('REVERB_HOST') ? 'http://'.env('REVERB_HOST').':'.env('REVERB_PORT', 8080) : null),
        'server' => env('REHEARSAL_REVERB_SERVER', false),
    ],
];
