<?php

use App\Models\User;

return [
    'database' => [
        'driver' => env('DB_CONNECTION', 'sqlite'),
        'disposable_server' => env('REHEARSAL_DB_DISPOSABLE', false),
        'aliases' => ['members'],
    ],
    'login' => ['guard' => 'web'],
    'factories' => ['User' => ['model' => User::class, 'states' => ['staff' => 'staff']]],
    'artisan' => ['about' => ['command' => 'about', 'fixed' => ['--only' => 'environment']]],
];
