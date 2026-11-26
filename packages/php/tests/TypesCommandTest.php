<?php

use Illuminate\Database\Eloquent\Model;
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
            'states' => ['sold' => 'sold'],
            'relations' => ['seller' => 'User'],
            'types' => ['attributes' => ['status' => '"active" | "sold"']],
        ]],
        'rehearsal.artisan' => ['reindex' => ['rules' => ['force' => 'boolean'], 'fixed' => ['secret' => 'not exported']]],
        'rehearsal.fault_paths' => ['/listings/**'],
        'rehearsal.schedule' => ['expiry' => []],
    ]);
    Schema::connection('rehearsal_types')->create('listings', function (Blueprint $table) {
        $table->id();
        $table->integer('price')->nullable();
    });
    $this->app->register(ServiceProvider::class);
    app(Operations::class)->register('listing.mark', ['id' => 'required|integer', 'note' => 'nullable|string'], static fn (): ?string => null);
    $path = sys_get_temp_dir().'/rehearsal-types-'.bin2hex(random_bytes(8)).'.d.ts';
    try {
        $this->artisan('rehearsal:types', ['path' => $path, '--database' => 'rehearsal_types'])->assertSuccessful();
        expect(file_get_contents($path))
            ->toContain('declare module "@sti3bas/rehearsal"', '"Listing":', '"price"?: number | null;', '"secret"?: string | null;')
            ->toContain('"model": { "id": number;', '"status"?: "active" | "sold";', '"states": "sold";', '"seller": "User";')
            ->toContain('"listing.mark": { input: { "id": number; "note"?: string | null; }; output: string | null }')
            ->toContain('"reindex": { "force"?: boolean; }', '"schedules": "expiry";', '"faultPaths": "/listings/**";')
            ->not->toContain('not exported');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

final class TypeTestListing extends Model
{
    protected $table = 'listings';

    protected $hidden = ['secret'];

    protected $casts = ['secret' => 'string'];
}
