<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Sti3bas\Rehearsal\Operations;
use Sti3bas\Rehearsal\ServiceProvider;

uses(TestCase::class);

it('emits the public allowlists and best-effort model and operation types', function () {
    config([
        'database.connections.rehearsal_types' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.connections.rehearsal_types_empty' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'rehearsal.factories' => ['Listing' => [
            'model' => TypeTestListing::class,
            'factory' => TypeTestListingFactory::class,
            'states' => ['sold' => 'sold'],
            'relations' => ['seller' => 'User'],
            'types' => ['attributes' => ['status' => '"active" | "sold"']],
        ], 'DynamicListing' => [
            'model' => TypeTestListing::class,
            'factory' => TypeTestDynamicListingFactory::class,
        ], 'LongArrayListing' => [
            'model' => TypeTestListing::class,
            'factory' => TypeTestLongArrayListingFactory::class,
        ], 'AppendedListing' => [
            'model' => TypeTestAppendedListing::class,
            'factory' => TypeTestAppendedListingFactory::class,
        ]],
        'rehearsal.artisan' => ['reindex' => ['rules' => ['force' => 'boolean'], 'fixed' => ['secret' => 'not exported']]],
        'rehearsal.fault_paths' => ['/listings/**'],
        'rehearsal.schedule' => ['expiry' => []],
    ]);
    Schema::connection('rehearsal_types')->create('listings', function (Blueprint $table) {
        $table->id();
        $table->integer('price')->nullable();
        $table->string('title');
        $table->string('sku');
        $table->dateTime('published_at')->nullable();
    });
    $row = (new TypeTestListing)->setConnection('rehearsal_types')->forceFill([
        'title' => 'Chair',
        'sku' => 'A',
        'published_at' => 1_700_000_000,
    ]);
    $row->timestamps = false;
    $row->save();
    expect($row->newQuery()->where('published_at', 1_700_000_000)->exists())->toBeFalse()
        ->and($row->newQuery()->where('published_at', $row->getRawOriginal('published_at'))->exists())->toBeTrue();
    $this->app->register(ServiceProvider::class);
    expect((new TypeTestListing)->forceFill(['display_title' => 'Chair'])->toArray())->toBe(['title' => 'Chair']);
    expect((new TypeTestAppendedListing)->forceFill(['display_title' => 'Chair'])->toArray())
        ->toBe(['title' => 'Chair', 'display_title' => 'CHAIR']);
    app(Operations::class)->register('listing.mark', ['id' => 'required|integer', 'note' => 'nullable|string'], static fn (): ?string => null);
    app(Operations::class)->register('listing.partial', [], static fn (): TypeTestListing => (new TypeTestListing)->forceFill(['id' => 1]));
    $path = sys_get_temp_dir().'/rehearsal-types-'.bin2hex(random_bytes(8)).'.d.ts';
    try {
        $this->artisan('rehearsal:types', ['path' => $path, '--database' => 'rehearsal_types'])->assertSuccessful();
        $contents = file_get_contents($path);
        $listing = explode('"states":', explode('"Listing": {', $contents, 2)[1], 2)[0];
        $dynamic = explode('"states":', explode('"DynamicListing": {', $contents, 2)[1], 2)[0];
        $longArray = explode('"states":', explode('"LongArrayListing": {', $contents, 2)[1], 2)[0];
        $appended = explode('"states":', explode('"AppendedListing": {', $contents, 2)[1], 2)[0];
        [$input, $output] = explode('"model":', $listing, 2);
        [$model, $filters] = explode('"modelFilters":', $output, 2);
        expect($contents)
            ->toContain('declare module "@sti3bas/rehearsal"', '"Listing":', '"price"?: number | null;', '"secret"?: string | null;')
            ->toContain('"model": { "id": number;', '"status"?: "active" | "sold";', '"states": { "sold": []; };', '"seller": "User";')
            ->toContain('"listing.mark": { input: { "id": number; "note"?: string | null; }; output: string | null }')
            ->toContain('import type { ModelResult } from "@sti3bas/rehearsal";', '"listing.partial": { input: Record<string, never>; output: ModelResult<"Listing"> }')
            ->toContain('"reindex": { "force"?: boolean; }', '"schedules": "expiry";', '"faultPaths": "/listings/**";')
            ->not->toContain('not exported');
        expect($listing)
            ->toContain('"published_at"?: string | null;', '"sku"?: string;', '"factoryRequired": "title";')
            ->not->toContain('"factoryRequired": "sku"', '"factoryRequired": "secret"');
        expect($input)->toContain('"display_title"?: unknown;');
        expect($output)
            ->toContain('"modelColumns": "id" | "price" | "title" | "sku" | "published_at";')
            ->toContain('"metadata"?: unknown[] | Record<string, unknown> | null;', '"settings"?: Record<string, unknown> | null;', '"tags"?: unknown[] | Record<string, unknown> | null;', '"labels"?: unknown[] | Record<string, unknown> | null;')
            ->not->toContain('"display_title"');
        expect($input)->toContain('"metadata"?: unknown[] | Record<string, unknown> | null;', '"published_at"?: number | null;');
        expect($model)->toContain('"published_at"?: string | null;');
        expect($filters)->toContain('"published_at"?: string | null;')->not->toContain('"published_at"?: number | null;');
        expect($dynamic)->not->toContain('"factoryRequired"');
        expect($longArray)->toContain('"factoryRequired": "sku";');
        [, $appendedOutput] = explode('"model":', $appended, 2);
        [$appendedModel, $appendedFilters] = explode('"modelFilters":', $appendedOutput, 2);
        expect($appendedModel)->toContain('"display_title"?: unknown;')->not->toContain('"private_label"');
        expect($appendedFilters)->not->toContain('"display_title"', '"private_label"');
        expect($appended)->not->toContain('"factoryRequired"');

        $this->artisan('rehearsal:types', ['path' => $path, '--database' => 'rehearsal_types_empty'])->assertSuccessful();
        $withoutColumns = explode('"states":', explode('"LongArrayListing": {', file_get_contents($path), 2)[1], 2)[0];
        expect($withoutColumns)->toContain('"sku"?: unknown;')->not->toContain('"factoryRequired"');
        $withoutColumns = explode('"states":', explode('"AppendedListing": {', file_get_contents($path), 2)[1], 2)[0];
        [, $withoutColumns] = explode('"model":', $withoutColumns, 2);
        [$model, $columns] = explode('"modelColumns":', $withoutColumns, 2);
        expect($model)->toContain('"editorial_label"?: string;');
        expect($columns)->not->toContain('"editorial_label"', '"display_title"');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('serializes collections with string keys as JSON objects', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    $listing = (new TypeTestListing)->forceFill([
        'tags' => ['color' => 'blue'],
        'labels' => ['size' => 'small'],
    ]);

    expect($listing->toJson())->toBe('{"tags":{"color":"blue"},"labels":{"size":"small"}}');
});

/**
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property-write int|null $published_at
 * @property array|null $metadata
 */
final class TypeTestListing extends Model
{
    protected $table = 'listings';

    protected $hidden = ['secret'];

    protected $fillable = ['display_title'];

    protected $casts = [
        'secret' => 'string',
        'published_at' => 'datetime',
        'metadata' => 'encrypted:array',
        'settings' => 'encrypted:object',
        'tags' => 'encrypted:collection',
        'labels' => 'collection',
    ];

    public function setDisplayTitleAttribute(string $value): void
    {
        $this->attributes['title'] = $value;
    }
}

final class TypeTestListingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement(['Chair', 'Table']),
            'display_title' => 'Chair',
            'secret' => throw new LogicException('Type generation must not execute the factory'),
        ];
    }
}

final class TypeTestDynamicListingFactory extends Factory
{
    public function definition(): array
    {
        $title = 'Chair';

        return ['title' => $title];
    }
}

final class TypeTestLongArrayListingFactory extends Factory
{
    public function definition(): array
    {
        return array(
            'sku' => fake()->randomElement(array('A', 'B')),
        );
    }
}

/** @property-read string $editorial_label */
final class TypeTestAppendedListing extends Model
{
    protected $table = 'listings';

    protected $appends = ['display_title', 'private_label'];

    protected $hidden = ['private_label'];

    protected function displayTitle(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): string => strtoupper($attributes['title']),
            set: fn (string $value): array => ['title' => $value],
        );
    }

    public function getEditorialLabelAttribute(): string
    {
        return 'Editor’s pick';
    }
}

final class TypeTestAppendedListingFactory extends Factory
{
    public function definition(): array
    {
        return ['display_title' => 'Chair'];
    }
}
