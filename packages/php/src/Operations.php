<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Closure;
use Generator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Validator;
use LogicException;
use PhpToken;
use ReflectionClass;
use Sti3bas\Rehearsal\Attributes\Operation;

final class Operations
{
    private const array DEFAULT_PATHS = ['tests/Rehearsal', 'tests/Helpers', 'app/Rehearsal'];

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
            $entries[$name] = compact('rules', 'handler', 'types');
        }

        return $entries;
    }

    public function invoke(string $name, array $data): mixed
    {
        $resolved = $this->resolved();
        if (isset($resolved[$name])) {
            $entry = $resolved[$name];
            $validated = Validator::validate($data, $entry['rules']);
            $handler = app($entry['handler']);

            return $handler($validated);
        }

        abort_unless(isset($this->entries[$name]), 404, 'Rehearsal operation is not registered.');
        [$rules, $handler] = $this->entries[$name];

        return $handler(Validator::validate($data, $rules));
    }

    /** @return array<string, array{rules: array<string, mixed>, handler: class-string, types: array<string, string>}> */
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
        $paths = self::DEFAULT_PATHS;
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
                    'types' => $definition['types'] ?? [],
                ];
            }
        }

        return $this->resolved = $entries;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, array{rules: array<string, mixed>, handler: class-string, types: array<string, string>}>
     */
    private function discover(array $paths): array
    {
        $entries = [];
        foreach ($this->classes($paths) as $class) {
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if (! $reflection->isInstantiable() || ! $reflection->hasMethod('__invoke')) {
                continue;
            }
            $attributes = $reflection->getAttributes(Operation::class);
            if ($attributes === []) {
                continue;
            }
            /** @var Operation $attribute */
            $attribute = $attributes[0]->newInstance();
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

            if (isset($entries[$attribute->name])) {
                throw new LogicException("Rehearsal operation [{$attribute->name}] is already registered.");
            }

            $entries[$attribute->name] = [
                'rules' => is_array($rules) ? $rules : [],
                'handler' => $class,
                'types' => is_array($types) ? $types : [],
            ];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $paths
     * @return Generator<string>
     */
    private function classes(array $paths): Generator
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
                    yield $class;
                }
            }
        }
    }

    /** @return Generator<string> */
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
