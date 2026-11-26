<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Sti3bas\Rehearsal\Attempt;
use Sti3bas\Rehearsal\Protocol;
use Sti3bas\Rehearsal\Run;
use Sti3bas\Rehearsal\ServiceProvider;

uses(TestCase::class);

it('keeps control routes disabled by default', function () {
    $this->app->register(ServiceProvider::class);
    $this->getJson('/_rehearsal/ready')->assertNotFound();
});

it('protects control calls and fences markers when a test slot is reused', function () {
    $run = new Run(bin2hex(random_bytes(12)));
    $capability = bin2hex(random_bytes(32));
    config([
        'rehearsal' => [
            'enabled' => true,
            'run' => $run->id,
            'capability' => $capability,
            'integrations' => [],
        ],
    ]);
    $this->app->register(ServiceProvider::class);
    $this->app->make(Protocol::class)->provision();
    Route::get('/probe', fn (Attempt $attempt) => ['slot' => $attempt->slot]);

    try {
        $this->getJson('/_rehearsal/ready')->assertOk()->assertJson(['protocol' => 1, 'run' => $run->id]);
        $this->postJson('/_rehearsal/start', ['protocol' => 1, 'slot' => 2])->assertUnauthorized();
        $this->getJson('/probe')->assertForbidden();
        $headers = [Protocol::CAPABILITY => $capability];
        $first = $this->postJson('/_rehearsal/start', ['protocol' => 1, 'slot' => 2], $headers)->assertOk()->json('marker');
        $this->getJson('/probe', [Protocol::MARKER => $first])->assertOk()->assertJson(['slot' => 2]);
        $this->getJson('/_rehearsal/typesense', [Protocol::MARKER => $first])->assertNotFound();
        $this->postJson('/_rehearsal/login', ['id' => 1], $headers + [Protocol::MARKER => $first])->assertNotFound();
        $second = $this->postJson('/_rehearsal/start', ['protocol' => 1, 'slot' => 2], $headers)->assertOk()->json('marker');
        expect($second)->not->toBe($first);
        $this->getJson('/probe', [Protocol::MARKER => $first])->assertStatus(409);
        $this->getJson('/probe', [Protocol::MARKER => $second])->assertOk()->assertJson(['slot' => 2]);
        $this->postJson('/_rehearsal/call', ['name' => 'arbitrary', 'data' => []], [Protocol::MARKER => $second])
            ->assertUnauthorized();
        unlink($run->path('slot-2.attempt'));
        $this->getJson('/probe', [Protocol::MARKER => $second])->assertStatus(409);
    } finally {
        (new Filesystem)->deleteDirectory($run->path());
    }
});

it('requires an allowed environment even with valid managed settings', function () {
    $this->app->detectEnvironment(fn () => 'browser-tests');
    config([
        'rehearsal.enabled' => true,
        'rehearsal.run' => str_repeat('a', 24),
        'rehearsal.capability' => str_repeat('b', 64),
    ]);
    $this->app->register(ServiceProvider::class);
    $this->getJson('/_rehearsal/ready')->assertNotFound();
});

it('honors a custom environment and selective safe-default opt-outs', function () {
    $this->app->detectEnvironment(fn () => 'browser-tests');
    config([
        'cache.default' => 'file',
        'app.debug' => true,
        'rehearsal.enabled' => true,
        'rehearsal.run' => str_repeat('a', 24),
        'rehearsal.capability' => str_repeat('b', 64),
        'rehearsal.environments' => ['browser-tests'],
        'rehearsal.integrations' => [],
        'rehearsal.safe_defaults' => ['cache.default' => null],
        'rehearsal.route_mocks' => false,
    ]);
    $this->app->register(ServiceProvider::class);
    expect($this->app->bound(Run::class))->toBeTrue()
        ->and(collect(app('router')->getRoutes())->contains(fn ($route) => $route->uri() === '_rehearsal/route-mock'))->toBeFalse()
        ->and(config('cache.default'))->toBe('file')
        ->and(config('queue.default'))->toBe('null')
        ->and(config('app.debug'))->toBeFalse();
});
