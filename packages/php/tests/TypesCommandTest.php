<?php

use Illuminate\Database\Eloquent\Model;
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
    });
    $this->app->register(ServiceProvider::class);
    expect((new TypeTestListing)->forceFill(['display_title' => 'Chair'])->toArray())->toBe(['title' => 'Chair']);
    app(Operations::class)->register('listing.mark', ['id' => 'required|integer', 'note' => 'nullable|string'], static fn (): ?string => null);
    app(Operations::class)->register('listing.partial', [], static fn (): TypeTestListing => (new TypeTestListing)->forceFill(['id' => 1]));
    $path = sys_get_temp_dir().'/rehearsal-types-'.bin2hex(random_bytes(8)).'.d.ts';
    try {
        $this->artisan('rehearsal:types', ['path' => $path, '--database' => 'rehearsal_types'])->assertSuccessful();
        $contents = file_get_contents($path);
        $listing = explode('"states":', explode('"Listing": {', $contents, 2)[1], 2)[0];
        $dynamic = explode('"states":', explode('"DynamicListing": {', $contents, 2)[1], 2)[0];
        $longArray = explode('"states":', explode('"LongArrayListing": {', $contents, 2)[1], 2)[0];
        [$input, $output] = explode('"model":', $listing, 2);
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
            ->toContain('"modelColumns": "id" | "price" | "title" | "sku";')
            ->toContain('"published_at"?: string | null;')
            ->toContain('"metadata"?: unknown[] | Record<string, unknown> | null;', '"settings"?: Record<string, unknown> | null;', '"tags"?: unknown[] | Record<string, unknown> | null;', '"labels"?: unknown[] | Record<string, unknown> | null;')
            ->not->toContain('"display_title"');
        expect($input)->toContain('"metadata"?: unknown[] | Record<string, unknown> | null;', '"published_at"?: number | null;');
        expect($dynamic)->not->toContain('"factoryRequired"');
        expect($longArray)->toContain('"factoryRequired": "sku";');
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
