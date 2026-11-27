<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Generator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use PhpToken;
use ReflectionClass;
use ReflectionMethod;

final class Factories
{
    public const SNAPSHOT = 'factories.json';

    private ?array $entries = null;

    public function all(?Run $run = null): array
    {
        return $this->entries ??= $this->resolve(config('rehearsal.factories'), $run);
    }

    public function discover(): array
    {
        if (! config('rehearsal.factories.discover', true)) {
            return [];
        }
        $modelPaths = config('rehearsal.factories.model_paths', [app_path('Models')]);
        $factoryPaths = config('rehearsal.factories.factory_paths', [database_path('factories')]);

        $models = [];
        foreach ($this->classes($modelPaths, Model::class) as $model) {
            if (in_array(HasFactory::class, class_uses_recursive($model), true) && $this->usesModelFactory($model)) {
                $models[$model] = ['model' => $model];
            }
        }
        $candidates = [];
        foreach ($this->classes($factoryPaths, Factory::class) as $factory) {
            $model = (new $factory)->modelName();
            if (! isset($models[$model]) && is_subclass_of($model, Model::class) && (new ReflectionClass($model))->isInstantiable()) {
                $candidates[$model][$factory] = true;
            }
        }
        foreach ($candidates as $model => $factories) {
            if (count($factories) === 1) {
                $models[$model] = ['model' => $model, 'factory' => array_key_first($factories)];
            }
        }

        return $models;
    }

    private function resolve(array $configured, ?Run $run): array
    {
        $discover = $configured['discover'] ?? true;
        unset($configured['discover'], $configured['model_paths'], $configured['factory_paths']);
        if (! $discover) {
            return $configured;
        }

        $snapshot = $run?->path(self::SNAPSHOT);
        $models = $snapshot !== null && is_file($snapshot)
            ? json_decode(file_get_contents($snapshot), true, flags: JSON_THROW_ON_ERROR)
            : $this->discover();

        $aliases = [];
        foreach ($models as $model => $definition) {
            $aliases[class_basename($model)][] = $definition;
        }
        $entries = [];
        foreach ($aliases as $alias => $definitions) {
            if (count($definitions) === 1) {
                $entries[$alias] = $definitions[0];
            }
        }
        ksort($entries);
        foreach ($configured as $alias => $definition) {
            $defaults = isset($definition['model']) ? ($models[$definition['model']] ?? []) : ($entries[$alias] ?? []);
            $entries[$alias] = array_replace($defaults, $definition);
        }

        return $entries;
    }

    private function usesModelFactory(string $model): bool
    {
        if ((new ReflectionMethod($model, 'newFactory'))->invoke(null) instanceof Factory) {
            return true;
        }

        $factory = Factory::resolveFactoryName($model);

        return is_subclass_of($factory, Factory::class) && (new ReflectionClass($factory))->isInstantiable();
    }

    private function classes(array $paths, string $base): Generator
    {
        $files = new Filesystem;
        foreach ($paths as $path) {
            $path = preg_match('~^(?:[/\\\\]|[a-z]:[/\\\\])~i', $path) ? $path : base_path($path);
            if (! $files->isDirectory($path)) {
                continue;
            }
            foreach ($files->allFiles($path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach ($this->declarations($file->getContents()) as $class) {
                    if (is_subclass_of($class, $base) && (new ReflectionClass($class))->isInstantiable()) {
                        yield $class;
                    }
                }
            }
        }
    }

    private function declarations(string $source): Generator
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token) => ! $token->isIgnorable()));
        $namespace = '';
        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? null;
            if ($token->id === T_NAMESPACE) {
                $namespace = $next?->is([T_STRING, T_NAME_QUALIFIED]) ? $next->text.'\\' : '';
            } elseif ($token->id === T_CLASS && $next?->id === T_STRING) {
                yield $namespace.$next->text;
            }
        }
    }
}
