<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Contracts\Container\Container;
use Laravel\Scout\EngineManager;
use ReflectionClass;
use RuntimeException;

final class Integrations
{
    private ?string $cachedKey = null;

    /** @var array<string, class-string<Integration>>|null */
    private ?array $cachedClasses = null;

    public function __construct(private Container $container) {}

    /** @return array<string, class-string<Integration>> */
    public function classes(): array
    {
        $configured = config('rehearsal.integrations', []);
        if (! is_array($configured)) {
            return [];
        }

        $cacheKey = serialize([
            $configured,
            config('rehearsal.mailpit.api'),
            config('rehearsal.reverb.origin'),
            config('rehearsal.scout.engine'),
        ]);
        if ($this->cachedKey === $cacheKey && $this->cachedClasses !== null) {
            return $this->cachedClasses;
        }

        $discover = is_bool($configured['discover'] ?? null) ? $configured['discover'] : false;
        $paths = is_array($configured['paths'] ?? null) ? $configured['paths'] : ClassDiscovery::DEFAULT_HELPER_PATHS;

        $resolved = [];
        $disabled = [];
        foreach ($configured as $name => $class) {
            if (($name === 'discover' && is_bool($class)) || ($name === 'paths' && is_array($class))) {
                continue;
            }
            if ($class === false) {
                $disabled[$name] = true;

                continue;
            }
            $resolved[$name] = $class;
        }

        if ($discover) {
            foreach ($this->builtInDiscovered() as $name => $class) {
                if (! isset($disabled[$name]) && ! isset($resolved[$name]) && ! in_array($class, $resolved, true)) {
                    $resolved[$name] = $class;
                }
            }
            foreach (ClassDiscovery::classes($paths) as $class) {
                if (isset($disabled[$class]) || in_array($class, $resolved, true)) {
                    continue;
                }
                $reflection = new ReflectionClass($class);
                if (! $reflection->isInstantiable() || ! $reflection->implementsInterface(Integration::class)) {
                    continue;
                }
                $resolved[$class] = $class;
            }
        }

        $this->cachedKey = $cacheKey;
        $this->cachedClasses = $resolved;

        return $resolved;
    }

    public function has(string $class): bool
    {
        return in_array($class, $this->classes(), true);
    }

    /** @return array<string, Integration> */
    public function all(): array
    {
        $integrations = [];
        foreach ($this->classes() as $name => $class) {
            $integration = $this->container->make($class);
            if (! $integration instanceof Integration) {
                throw new RuntimeException("Rehearsal integration [$name] must implement Integration.");
            }

            $integrations[$name] = $integration;
        }

        return $integrations;
    }

    /** @return array<string, class-string<Integration>> */
    private function builtInDiscovered(): array
    {
        $discovered = [];
        if (LocalService::isOrigin(config('rehearsal.mailpit.api'))) {
            $discovered['mailpit'] = Mailpit::class;
        }
        if (LocalService::isOrigin(config('rehearsal.reverb.origin'))) {
            $discovered['reverb'] = Reverb::class;
        }
        $scoutEngine = config('rehearsal.scout.engine');
        if (class_exists(EngineManager::class) && in_array($scoutEngine, ['database', 'meilisearch', 'typesense'], true)) {
            $discovered['scout'] = Scout::class;
        }

        return $discovered;
    }
}
