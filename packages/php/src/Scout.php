<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Searchable;
use Meilisearch\Client;
use ReflectionClass;
use RuntimeException;

final class Scout implements Integration, ClientMetadata
{
    public function credentials(Attempt $attempt): JsonResponse
    {
        return response()->json($this->clientMetadata($attempt), headers: ['Cache-Control' => 'private, no-store']);
    }

    public function provision(Run $run): void
    {
        $engine = $this->engine();
        if ($engine !== 'database') {
            $this->api()->get('/health')->throw();
        }
    }

    public function bind(Attempt $attempt): void
    {
        $engine = $this->engine();
        $this->configure($engine, $attempt);
        if ($engine === 'database') {
            return;
        }
        $state = $this->read($attempt->path('scout.json'));
        if (! isset($state['key']['value'])) {
            throw new RuntimeException('Rehearsal Scout has no scoped key for this slot.');
        }
        $origin = $this->origin();
        $parts = LocalService::origin($origin);
        if ($engine === 'meilisearch') {
            config(['scout.meilisearch.host' => $origin, 'scout.meilisearch.key' => $state['key']['value']]);
            app()->forgetInstance(Client::class);
        } else {
            config(['scout.typesense.client-settings' => [
                'api_key' => $state['key']['value'],
                'nodes' => [['host' => $parts['host'], 'port' => $parts['port'] ?? 80, 'protocol' => 'http']],
                'connection_timeout_seconds' => 5,
                'num_retries' => 0,
            ]]);
        }
        app(EngineManager::class)->forgetDrivers();
    }

    public function reset(Attempt $attempt): void
    {
        $engine = $this->engine();
        if ($engine === 'database') {
            return;
        }
        $originalPrefix = (string) config('scout.prefix');
        $this->configure($engine, $attempt);
        $path = $attempt->path('scout.json');
        $state = $this->read($path);
        if (isset($state['key']['value'])) {
            $empty = [];
            if ($engine === 'typesense') {
                $this->deleteKey($state['searchKey']['id'] ?? null, false);
                if (count($state['indexes']) > 2) {
                    $empty = $this->emptyTypesenseIndexes();
                }
            }
            foreach (array_reverse($state['indexes']) as $name) {
                $this->owned($attempt->run, $name);
                if ($engine === 'meilisearch') {
                    $this->wait($this->api()->delete('/indexes/'.$name.'/documents')->throw());
                } elseif (! isset($empty[$name])) {
                    $this->api()->delete('/collections/'.$name.'/documents?truncate=true')->throw();
                }
            }
            if ($engine === 'typesense') {
                $this->resetTypesense($attempt, $path, $state, $originalPrefix);
            }

            return;
        }
        if ($state !== []) {
            $this->release($attempt->run, $path);
        }

        $models = $this->models($attempt, $originalPrefix);
        if ($models === []) {
            throw new RuntimeException('Rehearsal Scout requires at least one searchable model.');
        }
        $state = ['indexes' => []];
        $names = [];
        foreach ($models as $class) {
            $model = new $class;
            $name = $model->searchableAs();
            if ($name !== $model->indexableAs() || ! str_starts_with($name, $this->prefix($attempt))) {
                throw new RuntimeException('Scout models must use the slot prefix for search and indexing.');
            }
            $this->owned($attempt->run, $name);
            if ($engine === 'meilisearch') {
                $this->wait($this->api()->post('/indexes', ['uid' => $name, 'primaryKey' => $model->getScoutKeyName()])->throw());
            } else {
                $logicalName = substr($name, strlen($this->prefix($attempt)));
                $names[$logicalName] = $names[$originalPrefix.$logicalName] = $names[$name] = $name;
                $schema = method_exists($model, 'typesenseCollectionSchema') ? $model->typesenseCollectionSchema()
                    : config('scout.typesense.model-settings.'.$class.'.collection-schema', []);
                foreach ($schema['fields'] ?? [] as $i => $field) {
                    if (isset($field['reference'])) {
                        [$collection, $column] = explode('.', $field['reference'], 2) + [1 => null];
                        if (! isset($names[$collection]) || ! $column) {
                            throw new RuntimeException('Typesense references must target another configured slot collection.');
                        }
                        $schema['fields'][$i]['reference'] = $names[$collection].'.'.$column;
                    }
                }
                $this->api()->post('/collections', ['name' => $name] + $schema)->throw();
            }
            $state['indexes'][] = $name;
            $this->write($path, $state);
            if ($engine === 'meilisearch') {
                $configured = (array) config('scout.meilisearch.index-settings', []);
                $logicalName = substr($name, strlen($this->prefix($attempt)));
                $settings = array_replace(
                    $configured[$class] ?? [],
                    $configured[$name] ?? $configured[$originalPrefix.$logicalName] ?? $configured[$logicalName] ?? [],
                );
                if (config('scout.soft_delete', false) && in_array(SoftDeletes::class, class_uses_recursive($model), true)
                    && ! in_array('__soft_deleted', $settings['filterableAttributes'] ?? [], true)) {
                    $settings['filterableAttributes'][] = '__soft_deleted';
                }
                if ($settings) {
                    $this->wait($this->api()->patch('/indexes/'.$name.'/settings', $settings)->throw());
                }
            }
        }

        $key = $engine === 'meilisearch'
            ? $this->api()->post('/keys', [
                'actions' => ['search', 'documents.*', 'indexes.get', 'tasks.get'],
                'indexes' => $state['indexes'], 'expiresAt' => null,
            ])->throw()->json()
            : $this->api()->post('/keys', [
                'description' => $this->prefix($attempt), 'actions' => ['collections:get', 'documents:*'],
                'collections' => $state['indexes'],
            ])->throw()->json();
        $state['key'] = ['id' => $key['uid'] ?? $key['id'], 'value' => $key['key'] ?? $key['value']];
        $this->write($path, $state);
        if ($engine === 'typesense') {
            $this->resetTypesense($attempt, $path, $state, $originalPrefix);
        }
    }

    public function clientMetadata(Attempt $attempt): array
    {
        if ($this->engine() !== 'typesense') {
            return [];
        }

        return [
            'engine' => 'typesense',
            'origin' => $this->origin(),
            'collectionPrefix' => $this->prefix($attempt),
            'searchKey' => $this->read($attempt->path('scout.json'))['searchKey']['value'],
        ];
    }

    private function emptyTypesenseIndexes(): array
    {
        $response = $this->api()->get('/collections', ['exclude_fields' => 'fields']);
        $collections = $response->successful() ? $response->json() : null;
        $empty = [];
        foreach (is_array($collections) ? $collections : [] as $collection) {
            if (is_string($collection['name'] ?? null) && ($collection['num_documents'] ?? null) === 0) {
                $empty[$collection['name']] = true;
            }
        }

        return $empty;
    }

    private function resetTypesense(Attempt $attempt, string $path, array $state, string $originalPrefix): void
    {
        $adminKey = $this->adminKey();
        $baseline = config('rehearsal.scout.baseline_models', []);
        $models = $this->models($attempt, $originalPrefix);
        if (! is_array($baseline) || array_diff($baseline, $models)) {
            throw new RuntimeException('Typesense baseline models must be included in the configured Scout models.');
        }
        if ($baseline !== []) {
            $integrations = array_values(app(Integrations::class)->classes());
            $beforeScout = array_slice($integrations, 0, (int) array_search(self::class, $integrations, true));
            if (! in_array(Database::class, $beforeScout, true)) {
                throw new RuntimeException('Typesense baseline import requires the Rehearsal database integration before Scout.');
            }
            app(Database::class)->bind($attempt);
            $this->bind($attempt);
            foreach (array_intersect($models, $baseline) as $class) {
                $class::makeAllSearchable();
            }
        }
        $key = $this->api($adminKey)->post('/keys', [
            'description' => $this->prefix($attempt).$attempt->id,
            'actions' => ['documents:search'],
            'collections' => $state['indexes'],
        ])->throw()->json();
        $state['searchKey'] = ['id' => $key['id'], 'value' => $key['value']];
        $this->write($path, $state);
    }

    public function cleanup(Run $run): void
    {
        if ($this->engine() !== 'database') {
            foreach (glob($run->path('slot-*-scout.json')) ?: [] as $path) {
                $this->release($run, $path);
            }
        }
    }

    private function engine(): string
    {
        $engine = config('rehearsal.scout.engine');
        if (! class_exists(EngineManager::class) || ! in_array($engine, ['database', 'meilisearch', 'typesense'], true)) {
            throw new RuntimeException('Install Laravel Scout and select database, meilisearch or typesense.');
        }

        return $engine;
    }

    private function prefix(Attempt $attempt): string
    {
        return 'rh_'.$attempt->run->id.'_s'.$attempt->slot.'_';
    }

    private function configure(string $engine, Attempt $attempt): void
    {
        config(['scout.driver' => $engine, 'scout.queue' => false, 'scout.prefix' => $this->prefix($attempt)]);
        if ($engine !== 'database') {
            config(['scout.after_commit' => true]);
        }
    }

    private function api(?string $adminKey = null): PendingRequest
    {
        $origin = $this->origin();
        LocalService::origin($origin);
        $key = $adminKey ?? $this->adminKey();
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Rehearsal Scout requires a disposable service admin key.');
        }
        $request = Http::baseUrl($origin)->acceptJson()->withoutRedirecting()->timeout(5);

        return $this->engine() === 'meilisearch' ? $request->withToken($key) : $request->withHeader('X-TYPESENSE-API-KEY', $key);
    }

    private function origin(): ?string
    {
        $origin = config('rehearsal.scout.origin');
        if (is_string($origin) && $origin !== '') {
            return $origin;
        }
        if ($this->engine() === 'meilisearch') {
            $host = config('scout.meilisearch.host');

            return is_string($host) && $host !== '' ? $host : null;
        }
        $node = config('scout.typesense.client-settings.nodes.0');
        if (is_array($node) && is_string($node['host'] ?? null) && $node['host'] !== '') {
            $protocol = is_string($node['protocol'] ?? null) && $node['protocol'] !== '' ? $node['protocol'] : 'http';
            $port = isset($node['port']) && $node['port'] !== '' ? ':'.$node['port'] : '';

            return $protocol.'://'.$node['host'].$port;
        }

        return null;
    }

    private function adminKey(): ?string
    {
        $key = config('rehearsal.scout.admin_key');
        if (is_string($key) && $key !== '') {
            return $key;
        }
        $fallback = $this->engine() === 'meilisearch'
            ? config('scout.meilisearch.key')
            : config('scout.typesense.client-settings.api_key');

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /** @return list<class-string> */
    private function models(Attempt $attempt, string $originalPrefix): array
    {
        $configured = config('rehearsal.scout.models', []);
        if (! is_array($configured)) {
            return [];
        }

        $discover = is_bool($configured['discover'] ?? null) ? $configured['discover'] : false;
        $paths = is_array($configured['paths'] ?? null)
            ? $configured['paths']
            : ['app/Models', ...ClassDiscovery::DEFAULT_HELPER_PATHS];

        $explicit = [];
        $disabled = [];
        foreach ($configured as $key => $value) {
            if (($key === 'discover' && is_bool($value)) || ($key === 'paths' && is_array($value))) {
                continue;
            }
            if ($value === false && is_string($key)) {
                $disabled[$key] = true;

                continue;
            }
            if (is_string($value)) {
                $explicit[] = $value;
            }
        }

        if (! $discover) {
            return array_values(array_unique($explicit));
        }

        $discovered = [];
        foreach (ClassDiscovery::classes($paths) as $class) {
            if (isset($disabled[$class])) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if (! $reflection->isInstantiable()) {
                continue;
            }
            if (! in_array(Searchable::class, class_uses_recursive($class), true)) {
                continue;
            }
            $discovered[] = $class;
        }

        $all = array_values(array_unique([...$explicit, ...$discovered]));

        return $this->engine() === 'typesense' ? $this->sortTypesenseModels($all, $attempt, $originalPrefix) : $all;
    }

    /**
     * @param  list<class-string>  $models
     * @return list<class-string>
     */
    private function sortTypesenseModels(array $models, Attempt $attempt, string $originalPrefix): array
    {
        $prefix = $this->prefix($attempt);
        $byName = [];
        $refs = [];
        foreach ($models as $class) {
            $model = new $class;
            $name = $model->searchableAs();
            $logical = str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
            $byName[$logical] = $class;
            $byName[$originalPrefix.$logical] = $class;
            $byName[$name] = $class;
            $schema = method_exists($model, 'typesenseCollectionSchema') ? $model->typesenseCollectionSchema()
                : config('scout.typesense.model-settings.'.$class.'.collection-schema', []);
            $refs[$class] = [];
            foreach ($schema['fields'] ?? [] as $field) {
                if (isset($field['reference']) && is_string($field['reference'])) {
                    [$collection] = explode('.', $field['reference'], 2);
                    $refs[$class][] = $collection;
                }
            }
        }

        $sorted = [];
        $visited = [];
        $visit = static function (string $class) use (&$visit, &$sorted, &$visited, $refs, $byName): void {
            if (isset($visited[$class])) {
                return;
            }
            $visited[$class] = true;
            foreach ($refs[$class] ?? [] as $collection) {
                $target = $byName[$collection] ?? null;
                if ($target !== null && $target !== $class) {
                    $visit($target);
                }
            }
            $sorted[] = $class;
        };

        foreach ($models as $class) {
            $visit($class);
        }

        return $sorted;
    }

    private function owned(Run $run, string $name): void
    {
        if (! preg_match('/\Arh_'.$run->id.'_s[1-9][0-9]?_[a-zA-Z0-9_-]+\z/', $name)) {
            throw new RuntimeException('Rehearsal refused a Scout index outside its run.');
        }
    }

    private function read(string $path): array
    {
        return is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    private function write(string $path, array $state): void
    {
        $temp = $path.'.next';
        if (file_put_contents($temp, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || ! chmod($temp, 0600) || ! rename($temp, $path)) {
            throw new RuntimeException('Could not record Rehearsal Scout ownership.');
        }
    }

    private function release(Run $run, string $path): void
    {
        $state = $this->read($path);
        $meili = $this->engine() === 'meilisearch';
        $this->deleteKey($state['searchKey']['id'] ?? null, false);
        $this->deleteKey($state['key']['id'] ?? null, $meili);
        foreach (array_reverse($state['indexes']) as $name) {
            $this->owned($run, $name);
            $response = $this->api()->delete(($meili ? '/indexes/' : '/collections/').$name);
            if ($response->status() !== 404) {
                $response->throw();
                if ($meili) {
                    $this->wait($response, allowMissing: true);
                }
            }
        }
        unlink($path);
    }

    private function deleteKey(mixed $id, bool $meili): void
    {
        if ($id === null) {
            return;
        }
        if ($meili ? ! Str::isUuid($id) : (! is_int($id) || $id < 0)) {
            throw new RuntimeException('Invalid Rehearsal Scout key ownership record.');
        }
        $response = $this->api()->delete('/keys/'.$id);
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    private function wait(Response $response, bool $allowMissing = false): void
    {
        $task = $response->json('taskUid');
        $deadline = microtime(true) + 10;
        do {
            $status = $this->api()->get('/tasks/'.$task)->throw()->json();
            if ($status['status'] === 'succeeded' || ($allowMissing && ($status['error']['code'] ?? '') === 'index_not_found')) {
                return;
            }
            if (in_array($status['status'], ['failed', 'canceled'], true)) {
                break;
            }
            usleep(30_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Rehearsal Meilisearch task did not complete: '.($status['error']['code'] ?? $status['status']));
    }
}
