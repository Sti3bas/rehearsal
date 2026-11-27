<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Sti3bas\Rehearsal\Operations;
use Sti3bas\Rehearsal\QueueControl;
use Sti3bas\Rehearsal\Schedules;
use Sti3bas\Rehearsal\ServiceProvider;

uses(TestCase::class);

it('auto-discovers scheduled artisan commands with positional args and options', function () {
    $captured = [];
    Artisan::command('demo:expire', function () use (&$captured) {
        $captured[] = ['expire'];
    });
    Artisan::command('demo:warn {days}', function (string $days) use (&$captured) {
        $captured[] = ['warn', $days];
    });
    Artisan::command('demo:prune {--hours=24}', function (string $hours) use (&$captured) {
        $captured[] = ['prune', $hours];
    });

    $schedule = app(Schedule::class);
    $schedule->command('demo:expire')->hourly();
    $schedule->command('demo:warn', ['days' => 7])->daily();
    $schedule->command('demo:prune', ['--hours' => 336])->daily();

    $this->app->register(ServiceProvider::class);

    $discovered = app(Schedules::class)->all();
    expect(array_keys($discovered))->toContain('demo:expire', 'demo:warn 7', 'demo:prune --hours=336');
    expect($discovered['demo:warn 7'])->toBe(['command' => 'demo:warn', 'fixed' => ['days' => '7']]);
    expect($discovered['demo:prune --hours=336'])->toBe(['command' => 'demo:prune', 'fixed' => ['--hours' => '336']]);

    $result = QueueControl::schedule(['names' => ['demo:expire', 'demo:warn 7', 'demo:prune --hours=336']]);
    expect($result['results'])->toBe([
        ['name' => 'demo:expire', 'code' => 0],
        ['name' => 'demo:warn 7', 'code' => 0],
        ['name' => 'demo:prune --hours=336', 'code' => 0],
    ]);
    expect($captured)->toBe([
        ['expire'],
        ['warn', '7'],
        ['prune', '336'],
    ]);

    config(['rehearsal.schedule' => ['demo:expire' => false]]);
    expect(app(Schedules::class)->all())->not->toHaveKey('demo:expire');

    config(['rehearsal.schedule' => ['discover' => false, 'manual:only' => ['command' => 'demo:expire']]]);
    expect(array_keys(app(Schedules::class)->all()))->toBe(['manual:only']);

    config(['rehearsal.schedule' => ['discover' => ['command' => 'demo:expire']]]);
    expect(app(Schedules::class)->all())->toHaveKey('discover');
});

it('parses space-separated options, repeated array options, apostrophes, and custom artisan paths', function () {
    $captured = [];
    Artisan::command('demo:multi {--tag=*} {--hours=24} {--label=}', function () use (&$captured) {
        $captured[] = [
            'tags' => $this->option('tag'),
            'hours' => $this->option('hours'),
            'label' => $this->option('label'),
        ];
    });

    $schedule = app(Schedule::class);
    $schedule->command("demo:multi --hours 336 --tag=alpha --tag=beta --label='O'\''Reilly'")->daily();

    $this->app->register(ServiceProvider::class);

    $discovered = app(Schedules::class)->all();
    $name = array_key_first($discovered);
    expect($name)->not->toBeNull();
    expect($discovered[$name]['fixed'])->toBe([
        '--hours' => '336',
        '--tag' => ['alpha', 'beta'],
        '--label' => "O'Reilly",
    ]);

    QueueControl::schedule(['names' => [$name]]);
    expect($captured)->toBe([[
        'tags' => ['alpha', 'beta'],
        'hours' => '336',
        'label' => "O'Reilly",
    ]]);
});

it('auto-discovers #[Operation] classes with rules and types and generates declarations', function () {
    $dir = sys_get_temp_dir().'/rehearsal-ops-'.bin2hex(random_bytes(8));
    mkdir($dir, 0777, true);
    $suffix = bin2hex(random_bytes(4));
    $ns = 'RehearsalOpsTest'.$suffix;

    file_put_contents($dir.'/AttrOp.php', <<<PHP
<?php
namespace {$ns};

use Sti3bas\Rehearsal\Attributes\Operation;

#[Operation(
    name: 'demo.add',
    rules: ['a' => 'required|integer', 'b' => 'required|integer'],
)]
final class AttrOp
{
    public function __invoke(array \$data): int
    {
        return (int) \$data['a'] + (int) \$data['b'];
    }
}
PHP);

    file_put_contents($dir.'/MethodOp.php', <<<PHP
<?php
namespace {$ns};

use Sti3bas\Rehearsal\Attributes\Operation;

#[Operation('demo.greet')]
final class MethodOp
{
    public function rules(): array
    {
        return ['who' => 'required|string'];
    }

    public static function types(): array
    {
        return ['output' => '{ greeting: string }'];
    }

    public function __invoke(array \$data): array
    {
        return ['greeting' => 'hi '.\$data['who']];
    }
}
PHP);

    file_put_contents($dir.'/PlainHelper.php', <<<PHP
<?php
namespace {$ns};

final class PlainHelper
{
    public function __invoke(): string
    {
        return 'ignored';
    }
}
PHP);

    require_once $dir.'/AttrOp.php';
    require_once $dir.'/MethodOp.php';
    require_once $dir.'/PlainHelper.php';

    $typesPath = sys_get_temp_dir().'/rehearsal-ops-types-'.bin2hex(random_bytes(8)).'.d.ts';

    try {
        config([
            'rehearsal.operations' => ['discover' => true, 'paths' => [$dir]],
            'rehearsal.factories' => ['discover' => false],
        ]);
        $this->app->register(ServiceProvider::class);

        $operations = app(Operations::class);
        expect($operations->invoke('demo.add', ['a' => 2, 'b' => 5]))->toBe(7);
        expect($operations->invoke('demo.greet', ['who' => 'rehearsal']))->toBe(['greeting' => 'hi rehearsal']);
        expect($operations->typeDefinitions())->toHaveKeys(['demo.add', 'demo.greet'])->not->toHaveKey('PlainHelper');

        $this->artisan('rehearsal:types', ['path' => $typesPath])->assertSuccessful();
        $contents = file_get_contents($typesPath);
        expect($contents)
            ->toContain('"demo.add": { input: { "a": number; "b": number; }; output: number }')
            ->toContain('"demo.greet": { input: { "who": string; }; output: { greeting: string } }');
    } finally {
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
        if (is_file($typesPath)) {
            unlink($typesPath);
        }
    }
});

it('auto-discovers #[Callback] classes, custom integrations, Horizon queues, Artisan commands, and route fault paths', function () {
    $dir = sys_get_temp_dir().'/rehearsal-zero-'.bin2hex(random_bytes(8));
    mkdir($dir, 0777, true);
    $ns = 'Tests\\Fixtures\\Zero'.bin2hex(random_bytes(4));

    file_put_contents($dir.'/StaticCallback.php', <<<PHP
<?php
namespace {$ns};

use Illuminate\\Http\\Request;
use Sti3bas\\Rehearsal\\Attributes\\Callback;
use Sti3bas\\Rehearsal\\CallbackReference;

#[Callback('/webhooks/demo')]
final class StaticCallback implements CallbackReference
{
    public function verifiedReference(Request \$request): ?string
    {
        return 'ref-1';
    }
}
PHP);

    file_put_contents($dir.'/DynamicCallback.php', <<<PHP
<?php
namespace {$ns};

use Illuminate\\Http\\Request;
use Sti3bas\\Rehearsal\\Attributes\\Callback;
use Sti3bas\\Rehearsal\\CallbackReference;

#[Callback]
final class DynamicCallback implements CallbackReference
{
    public static function path(): string
    {
        return '/stripe/webhook';
    }

    public function verifiedReference(Request \$request): ?string
    {
        return 'cus_123';
    }
}
PHP);

    file_put_contents($dir.'/CustomIntegration.php', <<<PHP
<?php
namespace {$ns};

use Sti3bas\\Rehearsal\\Attempt;
use Sti3bas\\Rehearsal\\Integration;
use Sti3bas\\Rehearsal\\Run;

final class CustomIntegration implements Integration
{
    public function provision(Run \$run): void {}
    public function bind(Attempt \$attempt): void {}
    public function reset(Attempt \$attempt): void {}
    public function cleanup(Run \$run): void {}
}
PHP);

    require_once $dir.'/StaticCallback.php';
    require_once $dir.'/DynamicCallback.php';
    require_once $dir.'/CustomIntegration.php';

    $typesPath = sys_get_temp_dir().'/rehearsal-zero-types-'.bin2hex(random_bytes(8)).'.d.ts';

    try {
        \Illuminate\Support\Facades\Route::get('/api/items/{item}/offers', static fn () => ['ok' => true]);
        config([
            'rehearsal.callbacks' => ['discover' => true, 'paths' => [$dir]],
            'rehearsal.integrations' => [
                'discover' => true,
                'paths' => [$dir],
                'database' => \Sti3bas\Rehearsal\Database::class,
            ],
            'rehearsal.mailpit.api' => 'http://127.0.0.1:8025',
            'rehearsal.reverb.origin' => 'http://127.0.0.1:18080',
            'rehearsal.queues' => null,
            'rehearsal.artisan' => ['discover' => true],
            'rehearsal.fault_paths' => ['discover' => true],
            'rehearsal.factories' => ['discover' => false],
            'rehearsal.operations' => ['discover' => false],
            'horizon.defaults' => ['sup-1' => ['queue' => ['high', 'default']]],
            'horizon.environments' => ['production' => ['sup-2' => ['queue' => ['processing', 'moderation']]]],
        ]);
        $this->app->register(ServiceProvider::class);

        expect(\Sti3bas\Rehearsal\Callbacks::all())
            ->toHaveKey('/webhooks/demo', $ns.'\\StaticCallback')
            ->toHaveKey('/stripe/webhook', $ns.'\\DynamicCallback');

        $integrations = app(\Sti3bas\Rehearsal\Integrations::class)->classes();
        expect($integrations)
            ->toContain(\Sti3bas\Rehearsal\Database::class)
            ->toContain(\Sti3bas\Rehearsal\Mailpit::class)
            ->toContain(\Sti3bas\Rehearsal\Reverb::class)
            ->toContain($ns.'\\CustomIntegration');

        expect(\Sti3bas\Rehearsal\QueueControl::queues())
            ->toContain('default', 'high', 'processing', 'moderation');

        expect(\Sti3bas\Rehearsal\RouteMocks::faultPathAllowed('/api/items/*/offers'))->toBeTrue();
        expect(\Sti3bas\Rehearsal\RouteMocks::faultPathAllowed('/_rehearsal/call'))->toBeFalse();

        $this->artisan('rehearsal:types', ['path' => $typesPath])->assertSuccessful();
        $contents = file_get_contents($typesPath);
        expect($contents)
            ->toContain('"/api/items/*/offers"')
            ->toContain('"list": Record<string, unknown>');
    } finally {
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
        if (is_file($typesPath)) {
            unlink($typesPath);
        }
    }
});

it('discovers method-level #[Operation] handlers, binds typed args, and reflects PHP/PHPDoc types', function () {
    $dir = sys_get_temp_dir().'/rehearsal-method-ops-'.bin2hex(random_bytes(8));
    mkdir($dir, 0777, true);
    $ns = 'Tests\\Fixtures\\MethodOps'.bin2hex(random_bytes(4));

    file_put_contents($dir.'/BillingOps.php', <<<PHP
<?php
namespace {$ns};

use Sti3bas\\Rehearsal\\Attributes\\Operation;

final class BillingOps
{
    /**
     * @param  'standard'|'premium'  \$plan
     * @return array{userId: int, plan: string, trialDays: int, notes: list<string>}
     */
    #[Operation('billing.subscribe')]
    public static function subscribe(int \$userId, string \$plan, int \$trialDays = 14, ?string \$note = null): array
    {
        return [
            'userId' => \$userId,
            'plan' => \$plan,
            'trialDays' => \$trialDays,
            'notes' => \$note === null ? [] : [\$note],
        ];
    }

    /** @return list<int> */
    #[Operation]
    public function activeIds(): array
    {
        return [1, 2, 3];
    }
}
PHP);

    require_once $dir.'/BillingOps.php';
    $typesPath = sys_get_temp_dir().'/rehearsal-method-ops-types-'.bin2hex(random_bytes(8)).'.d.ts';

    try {
        config([
            'rehearsal.operations' => ['discover' => true, 'paths' => [$dir]],
            'rehearsal.factories' => ['discover' => false],
            'rehearsal.artisan' => ['discover' => false],
            'rehearsal.fault_paths' => false,
        ]);
        $this->app->register(ServiceProvider::class);

        $operations = app(\Sti3bas\Rehearsal\Operations::class);
        expect($operations->invoke('billing.subscribe', ['userId' => 42, 'plan' => 'premium']))
            ->toBe(['userId' => 42, 'plan' => 'premium', 'trialDays' => 14, 'notes' => []]);
        expect($operations->invoke('billing.subscribe', ['userId' => '7', 'plan' => 'standard', 'trialDays' => 30, 'note' => 'vip']))
            ->toBe(['userId' => 7, 'plan' => 'standard', 'trialDays' => 30, 'notes' => ['vip']]);
        expect($operations->invoke('billing-ops.active-ids', []))->toBe([1, 2, 3]);

        $this->artisan('rehearsal:types', ['path' => $typesPath])->assertSuccessful();
        $contents = file_get_contents($typesPath);
        expect($contents)
            ->toContain('"billing.subscribe": { input: { "userId": number; "plan": "standard" | "premium"; "trialDays"?: number; "note"?: string | null }; output: { userId: number; plan: string; trialDays: number; notes: string[] } }')
            ->toContain('"billing-ops.active-ids": { input: Record<string, never>; output: number[] }');
    } finally {
        if (is_file($dir.'/BillingOps.php')) {
            unlink($dir.'/BillingOps.php');
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
        if (is_file($typesPath)) {
            unlink($typesPath);
        }
    }
});
