<?php

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Sti3bas\Rehearsal\Attempt;
use Sti3bas\Rehearsal\Control;
use Sti3bas\Rehearsal\Factories;
use Sti3bas\Rehearsal\Run;
use Sti3bas\Rehearsal\ServiceProvider;

uses(TestCase::class);

beforeEach(function () {
    $this->app->register(ServiceProvider::class);
});

it('auto-discovers fluent factory states with typed parameters and hydrates arguments and for() relations', function () {
    config([
        'database.default' => 'factory_test',
        'database.connections.factory_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'rehearsal.capability' => bin2hex(random_bytes(32)),
        'rehearsal.factories' => [
            'discover' => false,
            'Owner' => ['model' => RichOwnerModel::class, 'factory' => RichOwnerFactory::class],
            'Item' => ['model' => RichItemModel::class, 'factory' => RichItemFactory::class],
        ],
    ]);
    Schema::create('rich_owner_models', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('rich_item_models', function (Blueprint $table) {
        $table->id();
        $table->foreignId('owner_id')->nullable();
        $table->string('status');
        $table->string('note')->nullable();
        $table->timestamp('sold_at')->nullable();
        $table->foreignId('buyer_id')->nullable();
    });

    $states = app(Factories::class)->states(['model' => RichItemModel::class, 'factory' => RichItemFactory::class]);
    expect(array_keys($states))->toBe(['ofStatus', 'soldTo', 'withNote'])
        ->and($states)->not->toHaveKey('unsupportedHelper')
        ->and($states)->not->toHaveKey('nonFactoryHelper');

    $run = new Run(bin2hex(random_bytes(12)));
    $this->app->instance(Run::class, $run);

    try {
        $ownerResp = app(Control::class)->call(
            Request::create('/_rehearsal/call', 'POST', [
                'name' => 'factory',
                'data' => ['items' => [['model' => 'Owner', 'attrs' => ['name' => 'Ada']]]],
            ], server: ['HTTP_X_REHEARSAL_CAPABILITY' => config('rehearsal.capability')]),
            new Attempt($run, 1, 'test'),
        );
        $ownerId = $ownerResp->getData(true)['data'][0]['id'];

        $itemResp = app(Control::class)->call(
            Request::create('/_rehearsal/call', 'POST', [
                'name' => 'factory',
                'data' => ['items' => [[
                    'model' => 'Item',
                    'calls' => [
                        ['for' => ['$rehearsalModel' => 'Owner', 'id' => $ownerId], 'relation' => 'owner'],
                        ['state' => 'ofStatus', 'args' => ['archived']],
                        ['state' => 'soldTo', 'args' => [['$rehearsalModel' => 'Owner', 'id' => $ownerId], '2026-05-01T12:00:00.000Z']],
                        ['attrs' => ['note' => 'overridden']],
                    ],
                    'attrs' => ['note' => 'final'],
                ]]],
            ], server: ['HTTP_X_REHEARSAL_CAPABILITY' => config('rehearsal.capability')]),
            new Attempt($run, 1, 'test'),
        );
        $item = $itemResp->getData(true)['data'][0];
        expect($item['owner_id'])->toBe($ownerId)
            ->and($item['buyer_id'])->toBe($ownerId)
            ->and($item['status'])->toBe('archived')
            ->and($item['note'])->toBe('final')
            ->and($item['sold_at'])->toStartWith('2026-05-01');

        $path = sys_get_temp_dir().'/rehearsal-rich-'.bin2hex(random_bytes(8)).'.d.ts';
        try {
            $this->artisan('rehearsal:types', ['path' => $path])->assertSuccessful();
            expect(file_get_contents($path))
                ->toContain('import type { FactoryModelRef } from "@sti3bas/rehearsal";')
                ->toContain('"ofStatus": [status: "draft" | "archived"];')
                ->toContain('"soldTo": [buyer: FactoryModelRef<"Owner">, soldAt?: string | Date | null];')
                ->toContain('"withNote": [note?: string];')
                ->not->toContain('unsupportedHelper')
                ->not->toContain('nonFactoryHelper');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    } finally {
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($run->path());
    }
});

enum RichItemStatus: string
{
    case Draft = 'draft';
    case Archived = 'archived';
}

final class RichOwnerModel extends Model
{
    public $timestamps = false;

    protected $table = 'rich_owner_models';

    protected $guarded = [];
}

final class RichOwnerFactory extends Factory
{
    public function modelName(): string
    {
        return RichOwnerModel::class;
    }

    public function definition(): array
    {
        return ['name' => 'Owner'];
    }
}

final class RichItemModel extends Model
{
    public $timestamps = false;

    protected $table = 'rich_item_models';

    protected $guarded = [];

    protected $casts = ['sold_at' => 'datetime'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(RichOwnerModel::class, 'owner_id');
    }
}

final class RichItemFactory extends Factory
{
    public function modelName(): string
    {
        return RichItemModel::class;
    }

    public function definition(): array
    {
        return ['status' => RichItemStatus::Draft->value];
    }

    public function ofStatus(RichItemStatus $status): static
    {
        return $this->state(['status' => $status->value]);
    }

    public function soldTo(RichOwnerModel $buyer, ?Carbon $soldAt = null): static
    {
        return $this->state([
            'buyer_id' => $buyer->getKey(),
            'sold_at' => $soldAt ?? now(),
        ]);
    }

    public function withNote(string $note = 'default'): static
    {
        return $this->state(['note' => $note]);
    }

    public function unsupportedHelper(SplFileInfo $file): static
    {
        return $this;
    }

    public function nonFactoryHelper(): string
    {
        return 'ignored';
    }
}
