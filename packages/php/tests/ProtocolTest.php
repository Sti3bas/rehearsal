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
    $this->app->detectEnvironment(fn () => 'playwright');
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
        $second = $this->postJson('/_rehearsal/start', ['protocol' => 1, 'slot' => 2], $headers)->assertOk()->json('marker');
        expect($second)->not->toBe($first);
        $this->getJson('/probe', [Protocol::MARKER => $first])->assertStatus(409);
        $this->getJson('/probe', [Protocol::MARKER => $second])->assertOk()->assertJson(['slot' => 2]);
        $this->postJson('/_rehearsal/call', ['name' => 'arbitrary', 'data' => []], [Protocol::MARKER => $second])
            ->assertUnauthorized();
    } finally {
        (new Filesystem)->deleteDirectory($run->path());
    }
});
