<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as LaravelProvider;

final class ServiceProvider extends LaravelProvider
{
    private const SAFE_DEFAULTS = [
        'session.driver' => 'array',
        'cache.default' => 'array',
        'queue.default' => 'null',
        'mail.default' => 'log',
        'broadcasting.default' => 'log',
        'scout.driver' => 'null',
        'app.debug' => false,
    ];

    public function register(): void
    {
        $path = __DIR__.'/../config/rehearsal.php';
        $this->mergeConfigFrom($path, 'rehearsal');

        $this->app->singleton(Operations::class);
        if (! $this->active()) {
            return;
        }

        $this->app->instance(Run::class, new Run(config('rehearsal.run')));
        config(['database.default' => 'rehearsal_unbound']);
        $defaults = config('rehearsal.safe_defaults');
        foreach (self::SAFE_DEFAULTS as $key => $default) {
            $value = array_key_exists($key, $defaults) ? $defaults[$key] : $default;
            if ($value !== null) {
                config([$key => $value]);
            }
        }
        if (in_array(Database::class, config('rehearsal.integrations'), true)) {
            $this->app->singleton(Database::class);
            $this->app->make(Database::class)->isolateConnections();
        }
        if (in_array(Filesystem::class, config('rehearsal.integrations'), true)) {
            Filesystem::configureDisks($this->app->make(Run::class));
        }
        $control = static fn (Request $request) => app()->bound(Run::class) && $request->isMethod('POST')
            && $request->is('_rehearsal/start', '_rehearsal/call', '_rehearsal/login');
        TrimStrings::skipWhen($control);
        ConvertEmptyStringsToNull::skipWhen($control);
        $this->callAfterResolving(Kernel::class, static fn ($kernel) => $kernel->prependMiddleware(BindAttempt::class));
    }

    public function boot(): void
    {
        if ($this->allowedEnvironment() && config('rehearsal.reverb.server') === true && $this->app->runningInConsole()) {
            ReverbApplications::register();
        }
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
        if (config('rehearsal.route_mocks') === true) {
            Route::get('/_rehearsal/route-mock', [RouteMocks::class, 'resolve']);
            Route::get('/_rehearsal/route-mock/gates/{gate}', [RouteMocks::class, 'gate'])->where('gate', '[a-f0-9]{32}');
        }
        if (config('rehearsal.scout.engine') === 'typesense' && in_array(Scout::class, config('rehearsal.integrations'), true)) {
            Route::get('/_rehearsal/typesense', [Scout::class, 'credentials']);
        }
        if (is_array(config('rehearsal.login'))) {
            Route::post('/_rehearsal/login', [Control::class, 'login'])
                ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class]);
        }
    }

    private function active(): bool
    {
        return $this->allowedEnvironment()
            && config('rehearsal.enabled') === true
            && is_string(config('rehearsal.run'))
            && preg_match('/\A[a-f0-9]{24}\z/', config('rehearsal.run'))
            && is_string(config('rehearsal.capability'))
            && preg_match('/\A[a-f0-9]{64}\z/', config('rehearsal.capability'));
    }

    private function allowedEnvironment(): bool
    {
        return in_array($this->app->environment(), config('rehearsal.environments'), true);
    }
}
