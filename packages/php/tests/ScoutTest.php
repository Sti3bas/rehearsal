<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Laravel\Scout\EngineManager;
use Orchestra\Testbench\TestCase;
use Sti3bas\Rehearsal\Attempt;
use Sti3bas\Rehearsal\Run;
use Sti3bas\Rehearsal\Scout;

uses(TestCase::class);

it('truncates Typesense collections unless Typesense reports them empty', function (bool $summariesAvailable) {
    if (! class_exists(EngineManager::class)) {
        class_alias(get_class(new class {}), EngineManager::class);
    }

    config(['rehearsal.scout' => [
        'engine' => 'typesense',
        'origin' => 'http://127.0.0.1:18108',
        'admin_key' => 'test',
    ]]);
    $run = new Run(bin2hex(random_bytes(12)));
    $run->ensureDirectory();
    $attempt = new Attempt($run, 1, 'test');
    $prefix = 'rh_'.$run->id.'_s1_';
    $path = $attempt->path('scout.json');
    file_put_contents($path, json_encode([
        'indexes' => [$prefix.'unknown', $prefix.'empty', $prefix.'populated'],
        'key' => ['id' => 1, 'value' => 'backend-key'],
        'searchKey' => ['id' => 2, 'value' => 'old-search-key'],
    ]));

    Http::preventStrayRequests();
    Http::fake([
        'http://127.0.0.1:18108/keys/2' => Http::response(),
        'http://127.0.0.1:18108/collections?exclude_fields=fields' => $summariesAvailable
            ? Http::response([
                ['name' => $prefix.'empty', 'num_documents' => 0],
                ['name' => $prefix.'populated', 'num_documents' => 1],
                ['name' => 'unowned', 'num_documents' => 1],
            ])
            : Http::response(status: 403),
        'http://127.0.0.1:18108/collections/'.$prefix.'*/documents?truncate=true' => Http::response(),
        'http://127.0.0.1:18108/keys' => Http::response(['id' => 3, 'value' => 'new-search-key']),
    ]);

    try {
        $scout = new Scout;
        $scout->reset($attempt);
        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->method().' '.substr($pair[0]->url(), strlen('http://127.0.0.1:18108')))->all();
        expect($requests)->toBe([
            'DELETE /keys/2',
            'GET /collections?exclude_fields=fields',
            'DELETE /collections/'.$prefix.'populated/documents?truncate=true',
            ...($summariesAvailable ? [] : ['DELETE /collections/'.$prefix.'empty/documents?truncate=true']),
            'DELETE /collections/'.$prefix.'unknown/documents?truncate=true',
            'POST /keys',
        ])->and($scout->clientMetadata($attempt)['searchKey'])->toBe('new-search-key');
    } finally {
        (new Filesystem)->deleteDirectory($run->path());
    }
})->with([true, false]);
