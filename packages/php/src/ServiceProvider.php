<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as LaravelProvider;

final class ServiceProvider extends LaravelProvider
{
    public function register(): void
    {
        $path = __DIR__.'/../config/rehearsal.php';
        $this->mergeConfigFrom($path, 'rehearsal');

        $this->app->singleton(Operations::class);
        if (! $this->active()) {
            return;
        }

        $this->app->instance(Run::class, new Run(config('rehearsal.run')));
        config([
            'database.default' => 'rehearsal_unbound',
            'session.driver' => 'array',
            'cache.default' => 'array',
            'queue.default' => 'null',
            'mail.default' => 'log',
            'broadcasting.default' => 'log',
            'app.debug' => false,
        ]);
        if (in_array(Database::class, config('rehearsal.integrations'), true)) {
            $this->app->singleton(Database::class);
            $this->app->make(Database::class)->isolateConnections();
        }
        $this->callAfterResolving(Kernel::class, static fn ($kernel) => $kernel->prependMiddleware(BindAttempt::class));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/rehearsal.php' => config_path('rehearsal.php')], 'rehearsal-config');
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }
        if (! $this->active()) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ProvisionCommand::class, CleanupCommand::class]);
        }

        Route::get('/_rehearsal/ready', [Control::class, 'ready']);
        Route::post('/_rehearsal/start', [Control::class, 'start']);
        Route::post('/_rehearsal/call', [Control::class, 'call']);
        Route::post('/_rehearsal/login', [Control::class, 'login'])
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class]);
    }

    private function active(): bool
    {
        return $this->app->environment('playwright')
            && config('rehearsal.enabled') === true
            && is_string(config('rehearsal.run'))
            && preg_match('/\A[a-f0-9]{24}\z/', config('rehearsal.run'))
            && is_string(config('rehearsal.capability'))
            && preg_match('/\A[a-f0-9]{64}\z/', config('rehearsal.capability'));
    }
}
