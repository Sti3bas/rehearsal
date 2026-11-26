<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final readonly class BindAttempt
{
    public function __construct(
        private Application $app,
        private Protocol $protocol,
        private Integrations $integrations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS') && $request->headers->has('Origin')
            && $request->headers->has('Access-Control-Request-Method')) {
            return $this->app->make(HandleCors::class)->handle($request, static fn () => abort(403));
        }

        if ($request->is('_rehearsal/ready', '_rehearsal/start')) {
            return $next($request);
        }

        $attempt = $this->protocol->resolve($request->header(Protocol::MARKER, $request->cookies->get(Protocol::MARKER_COOKIE, '')));

        $lease = $attempt->run->acquire('slot-'.$attempt->slot, LOCK_SH);
        try {
            $this->protocol->assertCurrent($attempt);
            $this->app->instance(Attempt::class, $attempt);
            foreach ($this->integrations->all() as $integration) {
                $integration->bind($attempt);
            }

            if (config('rehearsal.fault_paths') && ! $request->is('_rehearsal', '_rehearsal/*')
                && ($fault = RouteMocks::fault($attempt, $request)) !== null) {
                return $fault;
            }

            HttpFakes::bind($attempt);

            return $next($request);
        } finally {
            $this->app->terminating(static function () use ($lease): void {
                if (is_resource($lease)) {
                    try {
                        DB::purge('rehearsal');
                    } finally {
                        fclose($lease);
                    }
                }
            });
        }
    }
}
