<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Sti3bas\Rehearsal\Attempt;
use Sti3bas\Rehearsal\CallbackReference;
use Sti3bas\Rehearsal\Callbacks;
use Sti3bas\Rehearsal\Database;
use Sti3bas\Rehearsal\Protocol;
use Sti3bas\Rehearsal\Run;
use Sti3bas\Rehearsal\ServiceProvider;

uses(TestCase::class);

it('honors the SQLite transaction mode when attempts share a database across requests', function (?string $mode) {
    $run = new Run(bin2hex(random_bytes(12)));
    $run->ensureDirectory();
    $attempt = new Attempt($run, 1, 'test');
    $path = $attempt->path('database.sqlite');
    touch($path);
    config(['rehearsal.database' => ['driver' => 'sqlite'] + ($mode === null ? [] : ['sqlite_transaction_mode' => $mode])]);

    (new Database)->bind($attempt);
    $connection = DB::connection();
    try {
        $connection->statement('create table events (id integer primary key)');
        $otherRequest = new PDO('sqlite:'.$path, options: [PDO::ATTR_TIMEOUT => 0]);
        $connection->beginTransaction();
        $connection->select('select * from events');

        if ($mode === 'IMMEDIATE') {
            expect(fn () => $otherRequest->exec('insert into events values (1)'))
                ->toThrow(PDOException::class, 'database is locked');
            expect($connection->insert('insert into events values (1)'))->toBeTrue();
        } else {
            expect($otherRequest->exec('insert into events values (1)'))->toBe(1);
            expect(fn () => $connection->insert('insert into events values (2)'))
                ->toThrow(\Illuminate\Database\QueryException::class, 'database is locked');
        }
    } finally {
        $connection->rollBack();
        unset($otherRequest);
        DB::purge();
        (new Filesystem)->deleteDirectory($run->path());
    }
})->with([[null], ['IMMEDIATE']]);

it('binds verified loopback callbacks only to a current attempt', function () {
    $run = new Run(bin2hex(random_bytes(12)));
    $capability = bin2hex(random_bytes(32));
    $resolver = new class implements CallbackReference
    {
        public function verifiedReference(Request $request): ?string
        {
            abort_unless(hash_equals(hash_hmac('sha256', $request->getContent(), 'test-signing-key'), $request->header('X-Test-Signature', '')), 403);

            return $request->input('reference');
        }
    };
    $this->app->instance($resolver::class, $resolver);
    config(['rehearsal' => [
        'enabled' => true,
        'run' => $run->id,
        'capability' => $capability,
        'integrations' => [],
        'callbacks' => ['/event' => $resolver::class],
    ]]);
    $this->app->register(ServiceProvider::class);
    $protocol = $this->app->make(Protocol::class);
    $protocol->provision();
    Route::post('/event', fn (Attempt $attempt) => ['slot' => $attempt->slot]);
    Route::post('/other', fn () => ['ok' => true]);
    Route::post('/associate', function (Request $request, Callbacks $callbacks) {
        $callbacks->bind('/event', $request->input('reference'));

        return response()->noContent();
    });
    $callback = function (string $reference, array $headers = []) {
        $data = ['reference' => $reference];

        return $this->postJson('/event', $data, $headers + [
            'X-Test-Signature' => hash_hmac('sha256', json_encode($data), 'test-signing-key'),
        ]);
    };

    try {
        $markers = [];
        foreach (range(1, 4) as $slot) {
            $markers[$slot] = $protocol->begin($slot)['marker'];
            $this->postJson('/associate', ['reference' => 'payment-'.$slot], [Protocol::MARKER => $markers[$slot]])->assertNoContent();
        }

        foreach (range(1, 4) as $slot) {
            $callback('payment-'.$slot, [Protocol::MARKER => $markers[($slot % 4) + 1]])->assertOk()->assertJson(['slot' => $slot]);
        }
        $callback('unknown')->assertNoContent();
        $callback('payment-1', ['X-Test-Signature' => 'invalid', Protocol::MARKER => $markers[1]])->assertForbidden();
        $this->postJson('/other', ['reference' => 'payment-1'])->assertForbidden();
        $protocol->begin(1);
        $callback('payment-1')->assertStatus(409);
        $callback('payment-2')->assertOk()->assertJson(['slot' => 2]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1']);
        $callback('payment-2')->assertForbidden();
    } finally {
        (new Filesystem)->deleteDirectory($run->path());
    }
});

it('keeps control routes disabled by default', function () {
    $this->app->register(ServiceProvider::class);
    $this->getJson('/_rehearsal/ready')->assertNotFound();
});

it('protects control calls and fences markers when a test slot is reused', function () {
    $run = new Run(bin2hex(random_bytes(12)));
    $capability = bin2hex(random_bytes(32));
    $handler = new class
    {
        public function __invoke(array $data): string
        {
            return strtoupper($data['title']);
        }
    };
    $this->app->instance($handler::class, $handler);
    config([
        'rehearsal' => [
            'enabled' => true,
            'run' => $run->id,
            'capability' => $capability,
            'integrations' => [],
            'login' => false,
            'operations' => [
                'probe.title' => [
                    'rules' => ['title' => 'required|string'],
                    'handler' => $handler::class,
                ],
            ],
        ],
    ]);
    $this->app->register(ServiceProvider::class);
    $this->app->make(Protocol::class)->provision();
    Route::get('/probe', fn (Attempt $attempt) => ['slot' => $attempt->slot]);

    try {
        $this->getJson('/_rehearsal/ready')->assertOk()->assertJson(['protocol' => 2, 'run' => $run->id]);
        $this->postJson('/_rehearsal/start', ['protocol' => 1, 'slot' => 2])->assertUnauthorized();
        $this->getJson('/probe')->assertForbidden();
        $headers = [Protocol::CAPABILITY => $capability];
        $this->postJson('/_rehearsal/start', ['protocol' => 1, 'slot' => 2], $headers)->assertUnprocessable();
        $first = $this->postJson('/_rehearsal/start', ['protocol' => 2, 'slot' => 2], $headers)->assertOk()->json('marker');
        $controlHeaders = $headers + [Protocol::MARKER => $first];
        $this->postJson('/_rehearsal/call', ['name' => 'probe.title', 'data' => ['title' => 'report']], $controlHeaders)
            ->assertOk()->assertJson(['data' => 'REPORT']);
        $this->postJson('/_rehearsal/call', ['name' => 'probe.title', 'data' => ['title' => ['invalid']]], $controlHeaders)
            ->assertUnprocessable();
        $this->postJson('/_rehearsal/call', ['name' => $handler::class, 'data' => ['title' => 'report']], $controlHeaders)
            ->assertNotFound();
        $this->getJson('/probe', [Protocol::MARKER => $first])->assertOk()->assertJson(['slot' => 2]);
        $this->getJson('/_rehearsal/typesense', [Protocol::MARKER => $first])->assertNotFound();
        $this->postJson('/_rehearsal/login', ['id' => 1], $headers + [Protocol::MARKER => $first])->assertNotFound();
        $second = $this->postJson('/_rehearsal/start', ['protocol' => 2, 'slot' => 2], $headers)->assertOk()->json('marker');
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
