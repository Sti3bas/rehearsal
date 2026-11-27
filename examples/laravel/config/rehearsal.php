<?php

use App\Models\Category;
use App\Models\User;
use Sti3bas\Rehearsal\Clock;
use Sti3bas\Rehearsal\Database;
use Sti3bas\Rehearsal\Filesystem;
use Sti3bas\Rehearsal\LaravelState;
use Sti3bas\Rehearsal\Mailpit;
use Sti3bas\Rehearsal\Reverb;
use Sti3bas\Rehearsal\Scout;

$engine = env('REHEARSAL_INTEGRATION_SCOUT');
$serviceHost = env('REHEARSAL_INTEGRATION_SERVICE_HOST', '127.0.0.1');

return [
    'integrations' => [
        'database' => Database::class,
        'filesystem' => Filesystem::class,
        'laravel-state' => LaravelState::class,
        'clock' => Clock::class,
        'mailpit' => $engine ? Mailpit::class : false,
        'scout' => $engine ? Scout::class : false,
        'reverb' => env('REHEARSAL_INTEGRATION_REVERB') === true ? Reverb::class : false,
    ],
    'mailpit' => ['api' => "http://$serviceHost:18025", 'smtp_port' => 11025],
    'reverb' => ['origin' => 'http://127.0.0.1:18080', 'server' => env('REHEARSAL_REVERB_SERVER', false)],
    'scout' => [
        'engine' => $engine,
        'origin' => $engine === 'typesense' ? "http://$serviceHost:18108" : 'http://127.0.0.1:17700',
        'admin_key' => 'rehearsal_test_only',
        'models' => $engine === 'typesense' ? [Category::class, User::class] : [User::class],
        'baseline_models' => $engine === 'typesense' ? [Category::class, User::class] : [],
    ],
    'database' => [
        'driver' => env('DB_CONNECTION', 'sqlite'),
        'disposable_server' => env('REHEARSAL_DB_DISPOSABLE', false),
        'aliases' => ['members'],
    ],
    'login' => ['guard' => 'web'],
    'factories' => [
        'User' => ['states' => ['staff' => 'staff'], 'relations' => ['category' => 'Category']],
    ],
    'artisan' => ['about' => ['command' => 'about', 'fixed' => ['--only' => 'environment']]],
    'schedule' => [
        'probe' => ['command' => 'probe:schedule'],
        'fail-probe' => ['command' => 'probe:schedule', 'fixed' => ['--fail' => true]],
    ],
    'fault_paths' => ['/probe/redirect', '/login'],
    'route_mocks' => true,
];
