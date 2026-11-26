<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Support\Collection;
use Laravel\Reverb\Application;
use Laravel\Reverb\ApplicationManager;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Exceptions\InvalidApplication;
use Laravel\Reverb\Protocols\Pusher\ConnectionRegistry;
use React\EventLoop\Loop;

final class ReverbApplications implements ApplicationProvider
{
    private array $connected = [];

    public static function register(): void
    {
        config([
            'reverb.apps.provider' => 'rehearsal',
            'reverb.servers.reverb.scaling.enabled' => false,
            'cache.default' => 'array',
        ]);
        $provider = new self;
        app(ApplicationManager::class)->extend('rehearsal', static fn () => $provider);
        Loop::addPeriodicTimer(1, static fn () => $provider->prune());
    }

    public function all(): Collection
    {
        return collect($this->applications());
    }

    public function findById(string $id): Application
    {
        return $this->applications()[$id] ?? throw new InvalidApplication;
    }

    public function findByKey(string $key): Application
    {
        foreach ($this->applications() as $app) {
            if (hash_equals($app->key(), $key)) {
                return $this->connected[$app->id()] = $app;
            }
        }
        throw new InvalidApplication;
    }

    private function prune(): void
    {
        $live = $this->applications();
        foreach (array_diff_key($this->connected, $live) as $id => $app) {
            foreach (app(ConnectionRegistry::class)->for($app) as $connection) {
                $connection->disconnect();
            }
            unset($this->connected[$id]);
        }
    }

    private function applications(): array
    {
        $apps = [];
        foreach (glob(base_path('.rehearsal/runs/*/slot-*-reverb.json')) ?: [] as $path) {
            if (! preg_match('~/([a-f0-9]{24})/slot-([1-9][0-9]?)-reverb\.json$~', $path, $match)) {
                continue;
            }
            [, $run, $slot] = $match;
            $data = json_decode(@file_get_contents($path) ?: 'null', true);
            if (! is_array($data) || ! preg_match('/\A[a-f0-9]{32}\z/', $data['attempt'] ?? '')
                || ($data['id'] ?? '') !== "rh-$run-$slot-".$data['attempt']
                || @file_get_contents(dirname($path).'/slot-'.$slot.'.attempt') !== $data['attempt']) {
                continue;
            }
            $app = new Application($data['id'], $data['key'], $data['secret'], 30, 30, ['127.0.0.1', 'localhost', '[::1]'], 10_000,
                acceptClientEventsFrom: 'none');
            $apps[$app->id()] = $app;
        }

        return $apps;
    }
}
