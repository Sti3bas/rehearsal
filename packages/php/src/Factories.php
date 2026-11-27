<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use BackedEnum;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use PhpToken;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

final class Factories
{
    public const SNAPSHOT = 'factories.json';

    private ?array $entries = null;

    /** @var array<string, true>|null */
    private static ?array $baseFactoryMethods = null;

    public function all(?Run $run = null): array
    {
        return $this->entries ??= $this->resolve(config('rehearsal.factories'), $run);
    }

    public function factoryClass(array $definition): ?string
    {
        $explicit = $definition['factory'] ?? null;
        if (is_string($explicit) && is_subclass_of($explicit, Factory::class) && (new ReflectionClass($explicit))->isInstantiable()) {
            return $explicit;
        }

        $model = $definition['model'] ?? null;
        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            return null;
        }

        $reflection = new ReflectionClass($model);
        if ($reflection->hasMethod('newFactory')) {
            $hook = $reflection->getMethod('newFactory');
            if (! $this->isTraitMethod($hook)) {
                $returnType = $hook->getReturnType();
                if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
                    $name = $returnType->getName();
                    if (is_subclass_of($name, Factory::class) && (new ReflectionClass($name))->isInstantiable()) {
                        return $name;
                    }
                }
            }
        }

        $property = $reflection->hasProperty('factory') ? $reflection->getProperty('factory') : null;
        $attribute = $reflection->getAttributes(UseFactory::class)[0] ?? null;
        $candidate = ($property?->isStatic() && $property->isInitialized() ? $property->getValue() : null)
            ?? $attribute?->newInstance()->factoryClass
            ?? Factory::resolveFactoryName($model);

        return is_string($candidate) && is_subclass_of($candidate, Factory::class) && (new ReflectionClass($candidate))->isInstantiable()
            ? $candidate
            : null;
    }

    /**
     * @return array<string, array{method: string, params: list<array{name: string, optional: bool, nullable: bool, types: list<array{kind: string, class?: string}>}>}>
     */
    public function states(array $definition): array
    {
        $configured = $definition['states'] ?? null;
        $factoryClass = $this->factoryClass($definition);
        $states = [];

        if ($configured !== false && config('rehearsal.factories.discover_states', true) && $factoryClass !== null) {
            $reflection = new ReflectionClass($factoryClass);
            $base = self::baseFactoryMethods();
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $name = $method->getName();
                if ($method->isStatic() || $method->isAbstract() || str_starts_with($name, '__') || isset($base[$name])) {
                    continue;
                }
                if (! $this->returnsFactory($method, $factoryClass)) {
                    continue;
                }
                $params = $this->inspectParameters($method);
                if ($params === null) {
                    continue;
                }
                $states[$name] = ['method' => $name, 'params' => $params];
            }
        }

        if (is_array($configured)) {
            $reflection = $factoryClass !== null ? new ReflectionClass($factoryClass) : null;
            foreach ($configured as $alias => $target) {
                if ($target === false) {
                    unset($states[$alias]);
                } elseif (is_string($target)) {
                    $params = [];
                    if ($reflection?->hasMethod($target)) {
                        $inspected = $this->inspectParameters($reflection->getMethod($target));
                        if ($inspected !== null) {
                            $params = $inspected;
                        }
                    }
                    $states[$alias] = ['method' => $target, 'params' => $params];
                }
            }
        }

        ksort($states);

        return $states;
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
            $model = $this->standaloneModel($factory);
            if ($model !== null && ! isset($models[$model]) && is_subclass_of($model, Model::class) && (new ReflectionClass($model))->isInstantiable()) {
                $candidates[$model][$factory] = true;
            }
        }
        foreach ($candidates as $model => $factories) {
            if ($this->usesModelFactory($model)) {
                $models[$model] = ['model' => $model];
            } elseif (count($factories) === 1) {
                $models[$model] = ['model' => $model, 'factory' => array_key_first($factories)];
            }
        }

        return $models;
    }

    private function resolve(array $configured, ?Run $run): array
    {
        $discover = $configured['discover'] ?? true;
        unset($configured['discover'], $configured['discover_states'], $configured['model_paths'], $configured['factory_paths']);
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
        $reflection = new ReflectionClass($model);
        if (! $reflection->hasMethod('factory')) {
            return false;
        }
        $method = $reflection->getMethod('factory');
        if (! $method->isPublic() || ! $method->isStatic()) {
            return false;
        }
        if (! $this->isTraitMethod($method)) {
            return true;
        }

        $hook = $reflection->getMethod('newFactory');
        if (! $this->isTraitMethod($hook)) {
            return true;
        }

        $property = $reflection->hasProperty('factory') ? $reflection->getProperty('factory') : null;
        $attribute = $reflection->getAttributes(UseFactory::class)[0] ?? null;
        $factory = ($property?->isStatic() && $property->isInitialized() ? $property->getValue() : null)
            ?? $attribute?->newInstance()->factoryClass
            ?? Factory::resolveFactoryName($model);

        return is_string($factory) && is_subclass_of($factory, Factory::class) && (new ReflectionClass($factory))->isInstantiable();
    }

    private function isTraitMethod(ReflectionMethod $method): bool
    {
        $default = new ReflectionMethod(HasFactory::class, $method->getName());

        return $method->getFileName() === $default->getFileName() && $method->getStartLine() === $default->getStartLine();
    }

    private function standaloneModel(string $factory): ?string
    {
        $reflection = new ReflectionClass($factory);
        if ($reflection->getMethod('modelName')->getDeclaringClass()->getName() !== Factory::class) {
            return null;
        }
        $attribute = $reflection->getAttributes(UseModel::class)[0] ?? null;
        $model = $attribute?->newInstance()->class ?? $reflection->getDefaultProperties()['model'] ?? null;
        if (is_string($model)) {
            return $model;
        }

        $properties = $reflection->getStaticProperties();
        $resolvers = $properties['modelNameResolvers'] ?? [];
        if (isset($resolvers[$factory]) || isset($resolvers[Factory::class]) || isset($properties['modelNameResolver'])
            || $reflection->getConstructor()?->getDeclaringClass()->getName() !== Factory::class
            || $reflection->getMethod('appNamespace')->getDeclaringClass()->getName() !== Factory::class) {
            return null;
        }

        try {
            $namespace = app()->getNamespace();
        } catch (Throwable) {
            $namespace = 'App\\';
        }
        $relative = Str::replaceLast('Factory', '', Str::replaceFirst($factory::$namespace, '', $factory));
        $model = $namespace.'Models\\'.$relative;

        return class_exists($model) ? $model : $namespace.Str::replaceLast('Factory', '', class_basename($factory));
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

    /**
     * @return array<string, true>
     */
    private static function baseFactoryMethods(): array
    {
        if (self::$baseFactoryMethods !== null) {
            return self::$baseFactoryMethods;
        }
        $methods = [];
        foreach ((new ReflectionClass(Factory::class))->getMethods() as $method) {
            $methods[$method->getName()] = true;
        }

        return self::$baseFactoryMethods = $methods;
    }

    private function returnsFactory(ReflectionMethod $method, string $factoryClass): bool
    {
        $type = $method->getReturnType();
        if ($type === null) {
            return true;
        }
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $branch) {
            if (! $branch instanceof ReflectionNamedType) {
                continue;
            }
            $name = $branch->getName();
            if ($name === 'static' || $name === 'self' || $name === $factoryClass || is_a($name, Factory::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{name: string, optional: bool, nullable: bool, types: list<array{kind: string, class?: string}>}>|null
     */
    private function inspectParameters(ReflectionMethod $method): ?array
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            if ($param->isVariadic()) {
                return null;
            }
            $types = $this->resolveParameterTypes($param);
            if ($types === null) {
                return null;
            }
            $params[] = [
                'name' => $param->getName(),
                'optional' => $param->isOptional(),
                'nullable' => $param->allowsNull(),
                'types' => $types,
            ];
        }

        return $params;
    }

    /**
     * @return list<array{kind: string, class?: string}>|null
     */
    private function resolveParameterTypes(ReflectionParameter $param): ?array
    {
        $type = $param->getType();
        if ($type === null) {
            return [['kind' => 'mixed']];
        }
        if ($type instanceof ReflectionIntersectionType) {
            return null;
        }
        $branches = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        $resolved = [];
        $hasModel = false;
        $hasFactory = false;
        foreach ($branches as $branch) {
            if (! $branch instanceof ReflectionNamedType) {
                return null;
            }
            $name = $branch->getName();
            if ($name === 'null') {
                continue;
            }
            if ($branch->isBuiltin()) {
                $resolved[] = match ($name) {
                    'string', 'int', 'float', 'bool', 'true', 'false', 'array', 'mixed' => ['kind' => $name],
                    default => null,
                };
                if (end($resolved) === null) {
                    return null;
                }

                continue;
            }
            if (is_a($name, BackedEnum::class, true)) {
                $resolved[] = ['kind' => 'enum', 'class' => $name];
            } elseif (is_a($name, DateTimeInterface::class, true)) {
                $resolved[] = ['kind' => 'date', 'class' => $name];
            } elseif (is_a($name, Model::class, true)) {
                $hasModel = true;
                $resolved[] = ['kind' => 'model', 'class' => $name];
            } elseif (is_a($name, Factory::class, true)) {
                $hasFactory = true;
            } else {
                return null;
            }
        }
        if ($hasFactory && ! $hasModel) {
            return null;
        }

        return $resolved === [] ? null : $resolved;
    }
}
