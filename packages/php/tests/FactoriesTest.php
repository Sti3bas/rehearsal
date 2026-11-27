<?php

use Illuminate\Database\Eloquent\Attributes\UseFactory;
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
        'ExplicitBindingModel' => ['model' => ExplicitBindingModel::class],
        'StandaloneFactoryModel' => ['model' => StandaloneFactoryModel::class, 'factory' => StandaloneFactory::class],
    ]);
    expect(CustomFactoryModel::$hookCalls)->toBe(0);
});

it('skips standalone mappings that require a custom Laravel resolver without calling it', function () {
    $namespace = 'RehearsalResolverFixtures\\';
    (new ReflectionProperty($this->app, 'namespace'))->setValue($this->app, $namespace);
    class_alias(StandaloneFactoryModel::class, $namespace.'Models\\ResolverMapped');
    expect((new Factories)->all())->toHaveKey('ResolverMapped');

    $calls = 0;
    Factory::guessModelNamesUsing(function () use (&$calls) {
        $calls++;

        return FactorylessModel::class;
    });
    try {
        expect((new Factories)->all())
            ->not->toHaveKey('ResolverMapped')
            ->not->toHaveKey('FactorylessModel')
            ->toHaveKey('StandaloneFactoryModel');
        expect($calls)->toBe(0);
    } finally {
        Factory::flushState();
    }
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
        'ExplicitBindingModel' => ['model' => ExplicitBindingModel::class],
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

it('chooses the correct factory for an alias outside the model scan paths', function (bool $discover, bool $explicit) {
    config([
        'database.default' => 'factory_test',
        'database.connections.factory_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'rehearsal.capability' => bin2hex(random_bytes(32)),
        'rehearsal.factories.discover' => $discover,
        'rehearsal.factories.model_paths' => [],
        'rehearsal.factories.Configured' => ['model' => CustomFactoryModel::class] + ($explicit ? ['factory' => CustomDiscoveryFactory::class] : []),
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

    expect($response->getData(true)['data'][0]['source'])->toBe($explicit ? 'standalone' : 'model hook')
        ->and(CustomFactoryModel::$hookCalls)->toBe($explicit ? 0 : 1);
})->with([
    'model hook beats discovered standalone' => [true, false],
    'explicit factory wins with discovery' => [true, true],
    'explicit factory wins without discovery' => [false, true],
]);

it('binds an explicit factory to the configured model without changing its resolver', function () {
    config([
        'database.default' => 'factory_test',
        'database.connections.factory_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'rehearsal.capability' => bin2hex(random_bytes(32)),
        'rehearsal.factories.discover' => false,
        'rehearsal.factories.Configured' => [
            'model' => ExplicitBindingModel::class,
            'factory' => UnboundDiscoveryFactory::class,
            'states' => ['named' => 'named'],
        ],
    ]);
    Schema::create('explicit_binding_models', function (Blueprint $table) {
        $table->id();
        $table->string('source');
    });
    $run = new Run(bin2hex(random_bytes(12)));
    $this->app->instance(Run::class, $run);
    $request = Request::create('/_rehearsal/call', 'POST', [
        'name' => 'factory',
        'data' => ['items' => [['model' => 'Configured', 'count' => 2, 'states' => ['named'], 'attrs' => ['source' => 'override']]]],
    ], server: ['HTTP_X_REHEARSAL_CAPABILITY' => config('rehearsal.capability')]);

    UnboundDiscoveryFactory::guessModelNamesUsing(fn () => FactorylessModel::class);
    try {
        $response = app(Control::class)->call($request, new Attempt($run, 1, 'test'));

        expect(array_column($response->getData(true)['data'][0], 'source'))->toBe(['override', 'override'])
            ->and(ExplicitBindingModel::query()->count())->toBe(2)
            ->and(UnboundDiscoveryFactory::new()->modelName())->toBe(FactorylessModel::class);
    } finally {
        Factory::flushState();
    }
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

final class ResolverMappedFactory extends Factory
{
    public function definition(): array
    {
        return [];
    }
}

#[UseFactory(UnboundDiscoveryFactory::class)]
final class ExplicitBindingModel extends Model
{
    use HasFactory;

    public $timestamps = false;
}

final class UnboundDiscoveryFactory extends Factory
{
    public function definition(): array
    {
        return ['source' => 'factory'];
    }

    public function named(): static
    {
        if ($this->modelName() !== ExplicitBindingModel::class) {
            throw new LogicException('Expected the configured model before applying a state.');
        }

        return $this->state(['source' => 'named']);
    }
}
