<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Meilisearch\Client;
use RuntimeException;

final class Scout implements Integration
{
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
        $origin = config('rehearsal.scout.origin');
        $parts = LocalService::origin($origin);
        if ($engine === 'meilisearch') {
            config(['scout.meilisearch.host' => $origin, 'scout.meilisearch.key' => $state['key']['value']]);
            app()->forgetInstance(Client::class);
        } else {
            config(['scout.typesense.client-settings' => [
                'api_key' => $state['key']['value'],
                'nodes' => [['host' => trim($parts['host'], '[]'), 'port' => $parts['port'] ?? 80, 'protocol' => 'http']],
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
        $this->configure($engine, $attempt);
        $path = $attempt->path('scout.json');
        $state = $this->read($path);
        if (isset($state['key']['value'])) {
            foreach ($state['indexes'] as $name) {
                $this->owned($attempt->run, $name);
                if ($engine === 'meilisearch') {
                    $this->wait($this->api()->delete('/indexes/'.$name.'/documents')->throw());
                } else {
                    $this->api()->delete('/collections/'.$name.'/documents?truncate=true')->throw();
                }
            }

            return;
        }
        if ($state !== []) {
            $this->release($attempt->run, $path);
        }

        $models = config('rehearsal.scout.models', []);
        if ($models === []) {
            throw new RuntimeException('Rehearsal Scout requires an explicit list of searchable models.');
        }
        $state = ['indexes' => []];
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
                $schema = method_exists($model, 'typesenseCollectionSchema') ? $model->typesenseCollectionSchema()
                    : config('scout.typesense.model-settings.'.$class.'.collection-schema', []);
                $this->api()->post('/collections', ['name' => $name] + $schema)->throw();
            }
            $state['indexes'][] = $name;
            $this->write($path, $state);
            if ($engine === 'meilisearch' && $settings = config('scout.meilisearch.index-settings.'.$class)) {
                $this->wait($this->api()->patch('/indexes/'.$name.'/settings', $settings)->throw());
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
    }

    private function api(): PendingRequest
    {
        $origin = config('rehearsal.scout.origin');
        LocalService::origin($origin);
        $key = config('rehearsal.scout.admin_key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Rehearsal Scout requires a disposable service admin key.');
        }
        $request = Http::baseUrl($origin)->acceptJson()->withoutRedirecting()->timeout(5);

        return $this->engine() === 'meilisearch' ? $request->withToken($key) : $request->withHeader('X-TYPESENSE-API-KEY', $key);
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
        if (isset($state['key']['id'])) {
            $id = $state['key']['id'];
            if ($meili ? ! Str::isUuid($id) : (! is_int($id) || $id < 0)) {
                throw new RuntimeException('Invalid Rehearsal Scout key ownership record.');
            }
            $response = $this->api()->delete('/keys/'.$id);
            if ($response->status() !== 404) {
                $response->throw();
            }
        }
        foreach ($state['indexes'] as $name) {
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
