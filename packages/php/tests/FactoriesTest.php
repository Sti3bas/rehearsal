<?php

use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use Sti3bas\Rehearsal\Factories;
use Sti3bas\Rehearsal\Protocol;
use Sti3bas\Rehearsal\Run;
use Sti3bas\Rehearsal\ServiceProvider;

uses(TestCase::class);

beforeEach(function () {
    $this->app->register(ServiceProvider::class);
    config(['rehearsal.factories' => [
        'model_paths' => [__DIR__],
        'factory_paths' => [__DIR__],
    ]]);
});

it('discovers concrete model factories and standalone factories without running definitions', function () {
    expect(app(Factories::class)->all())->toBe([
        'CustomFactoryModel' => ['model' => CustomFactoryModel::class],
        'StandaloneFactoryModel' => ['model' => StandaloneFactoryModel::class, 'factory' => StandaloneFactory::class],
    ]);
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

it('reuses the managed run snapshot across application boots', function () {
    $run = new Run(bin2hex(random_bytes(12)));
    $this->app->instance(Run::class, $run);
    config(['rehearsal.integrations' => []]);
    try {
        app(Protocol::class)->provision();
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

    protected static function newFactory(): CustomDiscoveryFactory
    {
        return CustomDiscoveryFactory::new();
    }
}

final class CustomDiscoveryFactory extends Factory
{
    protected $model = CustomFactoryModel::class;

    public function definition(): array
    {
        throw new LogicException('Discovery must not evaluate a factory definition.');
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
    public function definition(): array
    {
        throw new LogicException('Discovery must not evaluate a factory definition.');
    }
}
