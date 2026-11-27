<?php

use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Sti3bas\Rehearsal\Attempt;
use Sti3bas\Rehearsal\Control;
use Sti3bas\Rehearsal\Factories;
use Sti3bas\Rehearsal\Protocol;
use Sti3bas\Rehearsal\Run;
use Sti3bas\Rehearsal\ServiceProvider;

uses(TestCase::class);

beforeEach(function () {
    $this->app->register(ServiceProvider::class);
    CustomFactoryModel::$hookCalls = 0;
    config(['rehearsal.factories' => [
        'model_paths' => [__DIR__],
        'factory_paths' => [__DIR__],
    ]]);
});

it('discovers concrete model factories and standalone factories without running factory code', function () {
    expect(app(Factories::class)->all())->toBe([
        'CustomFactoryModel' => ['model' => CustomFactoryModel::class],
        'StandaloneFactoryModel' => ['model' => StandaloneFactoryModel::class, 'factory' => StandaloneFactory::class],
    ]);
    expect(CustomFactoryModel::$hookCalls)->toBe(0);
});

it('merges explicit metadata and aliases and caches discovery once per application', function () {
    config([
        'rehearsal.factories.CustomFactoryModel' => ['states' => ['special' => 'special']],
        'rehearsal.factories.OtherAlias' => ['model' => StandaloneFactoryModel::class],
        'rehearsal.factories.StandaloneFactoryModel' => ['model' => CustomFactoryModel::class],
    ]);
    $entries = app(Factories::class)->all();
    expect($entries)->toBe([
        'CustomFactoryModel' => ['model' => CustomFactoryModel::class, 'states' => ['special' => 'special']],
        'StandaloneFactoryModel' => ['model' => CustomFactoryModel::class],
        'OtherAlias' => ['model' => StandaloneFactoryModel::class, 'factory' => StandaloneFactory::class],
    ]);
    config(['rehearsal.factories.discover' => false]);
    expect(app(Factories::class)->all())->toBe($entries);
});

it('uses only explicit aliases when discovery is disabled', function () {
    $explicit = ['model' => CustomFactoryModel::class];
    config(['rehearsal.factories.discover' => false, 'rehearsal.factories.Kept' => $explicit]);

    expect(app(Factories::class)->all())->toBe(['Kept' => $explicit]);
});

it('uses the configured model hook ahead of a standalone mapping outside the model scan paths', function () {
    config([
        'database.default' => 'factory_test',
        'database.connections.factory_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'rehearsal.capability' => bin2hex(random_bytes(32)),
        'rehearsal.factories.model_paths' => [],
        'rehearsal.factories.Configured' => ['model' => CustomFactoryModel::class],
    ]);
    Schema::create('custom_factory_models', function (Blueprint $table) {
        $table->id();
        $table->string('source');
    });
    $run = new Run(bin2hex(random_bytes(12)));
    $this->app->instance(Run::class, $run);
    $request = Request::create('/_rehearsal/call', 'POST', [
        'name' => 'factory',
        'data' => ['items' => [['model' => 'Configured']]],
    ], server: ['HTTP_X_REHEARSAL_CAPABILITY' => config('rehearsal.capability')]);

    $response = app(Control::class)->call($request, new Attempt($run, 1, 'test'));

    expect($response->getData(true)['data'][0]['source'])->toBe('model hook')
        ->and(CustomFactoryModel::$hookCalls)->toBe(1);
});

it('reuses the managed run snapshot across application boots', function () {
    $run = new Run(bin2hex(random_bytes(12)));
    $this->app->instance(Run::class, $run);
    config(['rehearsal.integrations' => []]);
    try {
        app(Protocol::class)->provision();
        expect(CustomFactoryModel::$hookCalls)->toBe(0);
        config(['rehearsal.factories.model_paths' => [], 'rehearsal.factories.factory_paths' => []]);
        expect((new Factories)->all())->toBe([])
            ->and((new Factories)->all($run))->toHaveKeys(['CustomFactoryModel', 'StandaloneFactoryModel']);
    } finally {
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($run->path());
    }
});

it('generates the discovered factory types without per-factory configuration', function () {
    $path = sys_get_temp_dir().'/rehearsal-discovered-'.bin2hex(random_bytes(8)).'.d.ts';
    try {
        $this->artisan('rehearsal:types', ['path' => $path])->assertSuccessful();
        expect(CustomFactoryModel::$hookCalls)->toBe(0);
        expect(file_get_contents($path))
            ->toContain('"StandaloneFactoryModel": { "attributes": { "active"?: boolean | null;')
            ->toContain('"model": { "id": number; "active"?: boolean | null; }; "relations": {}; "states": never;')
            ->not->toContain('"FactorylessModel":');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

final class CustomFactoryModel extends Model
{
    use HasFactory;

    public $timestamps = false;

    public static int $hookCalls = 0;

    protected static function newFactory(): CustomDiscoveryFactory
    {
        self::$hookCalls++;

        return CustomDiscoveryFactory::new()->state(['source' => 'model hook']);
    }
}

final class CustomDiscoveryFactory extends Factory
{
    protected $model = CustomFactoryModel::class;

    public function definition(): array
    {
        return ['source' => 'standalone'];
    }
}

final class FactorylessModel extends Model
{
    use HasFactory;
}

abstract class AbstractFactoryModel extends Model
{
    use HasFactory;
}

final class StandaloneFactoryModel extends Model
{
    public $timestamps = false;

    protected $casts = ['active' => 'boolean'];
}

#[UseModel(StandaloneFactoryModel::class)]
final class StandaloneFactory extends Factory
{
    public function __construct()
    {
        throw new LogicException('Discovery must not instantiate a factory.');
    }

    public function definition(): array
    {
        throw new LogicException('Discovery must not evaluate a factory definition.');
    }
}
