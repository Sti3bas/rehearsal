<?php

return [
    'connections' => [
        'members' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'outside' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ],
];
