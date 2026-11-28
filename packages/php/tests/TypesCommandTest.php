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
    app(Operations::class)->register('listing.mark', ['id' => 'required|integer', 'note' => 'nullable|string'], static fn (): ?string => null);
    $path = sys_get_temp_dir().'/rehearsal-types-'.bin2hex(random_bytes(8)).'.d.ts';
    try {
        $this->artisan('rehearsal:types', ['path' => $path, '--database' => 'rehearsal_types'])->assertSuccessful();
        $contents = file_get_contents($path);
        $listing = explode('"states":', explode('"Listing": {', $contents, 2)[1], 2)[0];
        $dynamic = explode('"states":', explode('"DynamicListing": {', $contents, 2)[1], 2)[0];
        expect($contents)
            ->toContain('declare module "@sti3bas/rehearsal"', '"Listing":', '"price"?: number | null;', '"secret"?: string | null;')
            ->toContain('"model": { "id": number;', '"status"?: "active" | "sold";', '"states": { "sold": []; };', '"seller": "User";')
            ->toContain('"listing.mark": { input: { "id": number; "note"?: string | null; }; output: string | null }')
            ->toContain('"reindex": { "force"?: boolean; }', '"schedules": "expiry";', '"faultPaths": "/listings/**";')
            ->not->toContain('not exported');
        expect($listing)
            ->toContain('"published_at"?: string | null;', '"sku"?: string;', '"factoryRequired": "title";')
            ->not->toContain('"factoryRequired": "sku"', '"factoryRequired": "secret"');
        expect($dynamic)->not->toContain('"factoryRequired"');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

/** @property \Illuminate\Support\Carbon|null $published_at */
final class TypeTestListing extends Model
{
    protected $table = 'listings';

    protected $hidden = ['secret'];

    protected $casts = ['secret' => 'string', 'published_at' => 'datetime'];
}

final class TypeTestListingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement(['Chair', 'Table']),
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
