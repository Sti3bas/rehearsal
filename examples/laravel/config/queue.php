<?php

return [
    'connections' => [
        'database' => [
            'driver' => 'database',
            'table' => 'rehearsal_jobs',
            'queue' => 'probes',
            'retry_after' => 90,
        ],
    ],
    'failed' => ['table' => 'rehearsal_failed_jobs'],
];
