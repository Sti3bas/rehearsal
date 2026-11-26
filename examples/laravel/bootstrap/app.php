<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php')
    ->withMiddleware(fn (Middleware $middleware) => $middleware->statefulApi())
    ->withExceptions()
    ->create();
