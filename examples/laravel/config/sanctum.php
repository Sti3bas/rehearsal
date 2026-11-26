<?php

return [
    'stateful' => [substr(env('REHEARSAL_INTEGRATION_FRONTEND_ORIGIN', 'http://127.0.0.1:8325'), strlen('http://'))],
];
