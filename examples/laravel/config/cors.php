<?php

return [
    'paths' => ['api/*', 'login', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST'],
    'allowed_origins' => array_filter([env('REHEARSAL_INTEGRATION_FRONTEND_ORIGIN')]),
    'allowed_headers' => ['Accept', 'Content-Type', 'X-XSRF-TOKEN'],
    'supports_credentials' => true,
];
