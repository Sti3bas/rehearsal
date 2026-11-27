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
