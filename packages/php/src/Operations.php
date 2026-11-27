<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Sti3bas\Rehearsal\Attributes\Operation;
use Throwable;

final class Operations
{
    private array $entries = [];

    private ?array $resolved = null;

    public function __construct(private readonly array $configured = []) {}

    public function register(string $name, array $rules, Closure $handler, array $types = []): void
    {
        $resolved = $this->resolved();
        if (isset($this->entries[$name]) || isset($resolved[$name])) {
            throw new LogicException("Rehearsal operation [$name] is already registered.");
        }

        $this->entries[$name] = [$rules, $handler, $types];
    }

    public function typeDefinitions(): array
    {
        $entries = $this->resolved();
        foreach ($this->entries as $name => [$rules, $handler, $types]) {
            $entries[$name] = ['rules' => $rules, 'handler' => $handler, 'method' => null, 'types' => $types];
        }

        return $entries;
    }

    public function invoke(string $name, array $data): mixed
    {
        $resolved = $this->resolved();
        if (isset($resolved[$name])) {
            $entry = $resolved[$name];
            $validated = $entry['rules'] !== [] ? Validator::validate($data, $entry['rules']) : $data;
            $methodName = $entry['method'] ?? '__invoke';
            $method = new ReflectionMethod($entry['handler'], $methodName);
            $target = $method->isStatic() ? null : app($entry['handler']);

            if ($this->usesPayloadArray($method, $entry['rules'])) {
                return $method->getNumberOfParameters() === 0
                    ? $method->invoke($target)
                    : $method->invoke($target, $validated);
            }

            return $method->invokeArgs($target, $this->bindParameters($method, $validated));
        }

        abort_unless(isset($this->entries[$name]), 404, 'Rehearsal operation is not registered.');
        [$rules, $handler] = $this->entries[$name];
        $validated = $rules !== [] ? Validator::validate($data, $rules) : $data;
        $reflection = new ReflectionFunction($handler);

        if (self::usesPayloadArray($reflection, $rules)) {
            return $reflection->getNumberOfParameters() === 0
                ? $reflection->invoke()
                : $reflection->invoke($validated);
        }

        return $reflection->invokeArgs($this->bindParameters($reflection, $validated));
    }

    public static function usesPayloadArray(ReflectionFunctionAbstract $method, array $rules): bool
    {
        $params = $method->getParameters();
        if (count($params) === 0) {
            return true;
        }
        if (count($params) !== 1) {
            return false;
        }
        $type = $params[0]->getType();
        $isArrayParam = $type === null || ($type instanceof ReflectionNamedType && in_array($type->getName(), ['array', 'iterable'], true));
        if (! $isArrayParam) {
            return false;
        }

        return ! ($method instanceof ReflectionMethod) || $method->getName() === '__invoke';
    }

    /** @return array<string, array{rules: array<string, mixed>, handler: class-string, method: string, types: array<string, string>}> */
    private function resolved(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $configured = $this->configured;
        $discover = true;
        if (array_key_exists('discover', $configured) && is_bool($configured['discover'])) {
            $discover = $configured['discover'];
            unset($configured['discover']);
        }
        $paths = ClassDiscovery::DEFAULT_HELPER_PATHS;
        if (array_key_exists('paths', $configured) && is_array($configured['paths']) && array_is_list($configured['paths'])) {
            $paths = $configured['paths'];
            unset($configured['paths']);
        }

        $entries = $discover ? $this->discover($paths) : [];

        foreach ($configured as $name => $definition) {
            if ($definition === false) {
                unset($entries[$name]);
            } elseif (is_array($definition) && isset($definition['handler'])) {
                $entries[$name] = [
                    'rules' => $definition['rules'] ?? [],
                    'handler' => $definition['handler'],
                    'method' => is_string($definition['method'] ?? null) ? $definition['method'] : '__invoke',
                    'types' => $definition['types'] ?? [],
                ];
            }
        }

        return $this->resolved = $entries;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, array{rules: array<string, mixed>, handler: class-string, method: string, types: array<string, string>}>
     */
    private function discover(array $paths): array
    {
        $entries = [];
        foreach (ClassDiscovery::classes($paths) as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            $classAttributes = $reflection->getAttributes(Operation::class);
            if ($classAttributes !== [] && $reflection->isInstantiable() && $reflection->hasMethod('__invoke')) {
                /** @var Operation $attribute */
                $attribute = $classAttributes[0]->newInstance();
                $name = $attribute->name ?? Str::kebab(class_basename($class));
                $instance = null;
                $rules = $attribute->rules;
                if ($rules === [] && $reflection->hasMethod('rules')) {
                    $rulesMethod = $reflection->getMethod('rules');
                    if ($rulesMethod->isPublic()) {
                        $rules = $rulesMethod->isStatic()
                            ? $rulesMethod->invoke(null)
                            : $rulesMethod->invoke($instance ??= app($class));
                    }
                }
                $types = $attribute->types;
                if ($types === [] && $reflection->hasMethod('types')) {
                    $typesMethod = $reflection->getMethod('types');
                    if ($typesMethod->isPublic()) {
                        $types = $typesMethod->isStatic()
                            ? $typesMethod->invoke(null)
                            : $typesMethod->invoke($instance ??= app($class));
                    }
                }

                if (isset($entries[$name])) {
                    throw new LogicException("Rehearsal operation [$name] is already registered.");
                }

                $entries[$name] = [
                    'rules' => is_array($rules) ? $rules : [],
                    'handler' => $class,
                    'method' => '__invoke',
                    'types' => is_array($types) ? $types : [],
                ];
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $methodAttributes = $method->getAttributes(Operation::class);
                if ($methodAttributes === []) {
                    continue;
                }
                if (! $method->isStatic() && ! $reflection->isInstantiable()) {
                    continue;
                }
                /** @var Operation $attribute */
                $attribute = $methodAttributes[0]->newInstance();
                $name = $attribute->name ?? Str::kebab(class_basename($class)).'.'.Str::kebab($method->getName());

                if (isset($entries[$name])) {
                    throw new LogicException("Rehearsal operation [$name] is already registered.");
                }

                $entries[$name] = [
                    'rules' => $attribute->rules,
                    'handler' => $class,
                    'method' => $method->getName(),
                    'types' => $attribute->types,
                ];
            }
        }

        return $entries;
    }

    /** @return list<mixed> */
    private function bindParameters(ReflectionFunctionAbstract $method, array $data): array
    {
        $args = [];
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (! array_key_exists($name, $data)) {
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();

                    continue;
                }
                if ($param->allowsNull()) {
                    $args[] = null;

                    continue;
                }
                abort(422, "Missing required operation argument [$name].");
            }

            $args[] = $this->coerceArgument($param, $data[$name]);
        }

        return $args;
    }

    private function coerceArgument(ReflectionParameter $param, mixed $value): mixed
    {
        $type = $param->getType();
        if ($value === null) {
            abort_unless($type === null || $param->allowsNull(), 422, "Operation argument [{$param->getName()}] cannot be null.");

            return null;
        }
        if ($type === null) {
            return $value;
        }

        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        $named = array_values(array_filter($types, static fn ($t) => $t instanceof ReflectionNamedType && $t->getName() !== 'null'));

        foreach ($named as $candidate) {
            $coerced = $this->tryCoerce($candidate->getName(), $value);
            if ($coerced !== null) {
                return $coerced[0];
            }
        }

        abort(422, "Invalid operation argument [{$param->getName()}].");
    }

    /** @return array{0: mixed}|null */
    private function tryCoerce(string $typeName, mixed $value): ?array
    {
        return match ($typeName) {
            'mixed' => [$value],
            'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? [(int) $value] : null,
            'float' => is_int($value) || is_float($value) || is_numeric($value) ? [(float) $value] : null,
            'string' => is_string($value) ? [$value] : null,
            'bool' => is_bool($value) ? [$value] : null,
            'true' => $value === true ? [true] : null,
            'false' => $value === false ? [false] : null,
            'array' => is_array($value) ? [$value] : null,
            default => $this->tryCoerceObject($typeName, $value),
        };
    }

    /** @return array{0: mixed}|null */
    private function tryCoerceObject(string $typeName, mixed $value): ?array
    {
        if (is_a($typeName, BackedEnum::class, true)) {
            if (! is_string($value) && ! is_int($value)) {
                return null;
            }
            $backing = (string) (new ReflectionEnum($typeName))->getBackingType();
            $key = $backing === 'int' ? (int) $value : (string) $value;
            $case = $typeName::tryFrom($key);

            return $case !== null ? [$case] : null;
        }

        if (is_a($typeName, DateTimeInterface::class, true)) {
            if (! is_string($value) || $value === '') {
                return null;
            }
            try {
                return match ($typeName) {
                    CarbonImmutable::class => [CarbonImmutable::parse($value)],
                    DateTimeImmutable::class => [new DateTimeImmutable($value)],
                    default => [Carbon::parse($value)],
                };
            } catch (Throwable) {
                return null;
            }
        }

        if (is_a($typeName, Model::class, true)) {
            $id = null;
            if (is_int($value) || is_string($value)) {
                $id = $value;
            } elseif (is_array($value) && isset($value['id']) && (is_int($value['id']) || is_string($value['id']))) {
                $id = $value['id'];
            }
            if ($id === null) {
                return null;
            }
            $model = $typeName::query()->find($id);

            return $model !== null ? [$model] : null;
        }

        return null;
    }
}
