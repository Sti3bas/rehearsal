<?php

use App\Models\User;

return [
    'login' => ['guard' => 'web'],
    'factories' => ['User' => ['model' => User::class, 'states' => ['staff' => 'staff']]],
    'artisan' => ['about' => ['command' => 'about', 'fixed' => ['--only' => 'environment']]],
];
