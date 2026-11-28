<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use ReflectionClass;
use ReflectionEnum;
use ReflectionProperty;
use Throwable;

final readonly class Control
{
    public function __construct(private Protocol $protocol, private Operations $operations, private Factories $factories) {}

    public function ready(): JsonResponse
    {
        abort_unless(is_file($this->protocol->run->path('ready')), 503);

        return response()->json(['protocol' => Protocol::VERSION, 'run' => $this->protocol->run->id]);
    }

    public function start(Request $request): JsonResponse
    {
        $this->protocol->authorize($request);
        $data = $request->validate([
            'slot' => ['required', 'integer', 'min:1', 'max:'.Run::MAX_SLOTS],
            'protocol' => ['required', 'integer', Rule::in([Protocol::VERSION])],
        ]);

        return response()->json($this->protocol->begin((int) $data['slot']));
    }

    public function call(Request $request, Attempt $attempt): JsonResponse
    {
        $this->protocol->authorize($request);
        $input = $request->validate(['name' => 'required|string|max:100', 'data' => 'present|array']);
        $data = $input['data'];
        $result = match ($input['name']) {
            'factory' => $this->factory($data),
            'model.query' => $this->modelQuery($data),
            'travel' => $this->travel($attempt, $data),
            'artisan' => $this->artisan($data),
            'queue.drain' => QueueControl::drain($data),
            'queue.failed' => QueueControl::failed($data),
            'schedule.run' => QueueControl::schedule($data),
            'route.mock' => RouteMocks::register($attempt, $data, false),
            'route.mock.clear' => RouteMocks::clear($attempt, false),
            'route.mock.release' => RouteMocks::release($attempt, $data),
            'route.fake' => RouteMocks::register($attempt, $data, true),
            'route.fake.clear' => RouteMocks::clear($attempt, true),
            'reverb.subscribed' => app(Reverb::class)->subscribed($data),
            'reverb.broadcast' => app(Reverb::class)->broadcast($data),
            'mail.inbox' => app(Mailpit::class)->mailbox($attempt, $data),
            'http.fake' => HttpFakes::register($attempt, $data),
            default => $this->operations->invoke($input['name'], $data),
        };

        return response()->json(['data' => $result]);
    }

    public function login(Request $request): JsonResponse
    {
        $this->protocol->authorize($request);
        $options = config('rehearsal.login');
        abort_unless($options === true || is_array($options), 404, 'Rehearsal login is not enabled.');
        $input = $request->validate(['id' => 'required']);
        abort_unless(is_int($input['id']) || is_string($input['id']), 422);
        $guardName = is_array($options) && is_string($options['guard'] ?? null) && $options['guard'] !== ''
            ? $options['guard']
            : null;
        $guard = Auth::guard($guardName);
        if ($guardName === null && ! $guard instanceof StatefulGuard && is_array(config('auth.guards.web'))) {
            $guard = Auth::guard('web');
        }
        abort_unless($guard instanceof StatefulGuard, 422, 'Rehearsal login requires a stateful guard.');
        abort_unless($guard->loginUsingId($input['id']), 404);
        $request->session()->regenerate();

        return response()->json(['data' => $guard->id()]);
    }

    private function factory(array $input): array
    {
        $factories = $this->factories->all($this->protocol->run);
        $data = Validator::validate($input, [
            'items' => 'required|array|list|min:1|max:100',
            'items.*' => 'required|array:model,attrs,states,calls,count,refs',
            'items.*.model' => ['required', 'string', Rule::in(array_keys($factories))],
            'items.*.attrs' => 'sometimes|array|max:100',
            'items.*.states' => 'sometimes|array|list|max:20',
            'items.*.calls' => 'sometimes|array|list|max:50',
            'items.*.count' => 'sometimes|integer|min:1|max:100',
            'items.*.refs' => 'sometimes|array|max:10',
            'items.*.refs.*' => 'required|integer|min:0',
        ]);
        abort_if(array_sum(array_map(fn ($item) => $item['count'] ?? 1, $data['items'])) > 100, 422);

        return DB::transaction(function () use ($data, $factories): array {
            $results = [];
            $configured = config('rehearsal.factories');
            foreach ($data['items'] as $item) {
                $definition = $factories[$item['model']];
                $model = $definition['model'] ?? '';
                abort_unless(is_subclass_of($model, Model::class), 422);
                if (isset($definition['factory'])) {
                    $class = $definition['factory'];
                    abort_unless(is_subclass_of($class, Factory::class), 422);
                    $factory = $class::new();
                } else {
                    abort_unless(is_callable([$model, 'factory']), 422);
                    $factory = $model::factory();
                }
                $states = $this->factories->states(['factory' => $factory::class, ...$definition]);
                $create = function () use ($factory, $model, $item, $definition, $states, $factories, $results, $data) {
                    foreach ($item['states'] ?? [] as $state) {
                        if (is_string($state)) {
                            $factory = $this->applyState($factory, $states, $state, [], $factories);
                        } elseif (is_array($state) && isset($state['name']) && is_string($state['name'])) {
                            $args = $state['args'] ?? [];
                            abort_unless(is_array($args) && array_is_list($args), 422, 'Invalid factory state arguments.');
                            $factory = $this->applyState($factory, $states, $state['name'], $args, $factories);
                        } else {
                            abort(422, 'Invalid factory state.');
                        }
                    }
                    foreach ($item['calls'] ?? [] as $call) {
                        abort_unless(is_array($call), 422, 'Invalid factory call.');
                        if (isset($call['state']) && is_string($call['state'])) {
                            $args = $call['args'] ?? [];
                            abort_unless(is_array($args) && array_is_list($args), 422, 'Invalid factory state arguments.');
                            $factory = $this->applyState($factory, $states, $call['state'], $args, $factories);
                        } elseif (isset($call['attrs']) && is_array($call['attrs'])) {
                            $factory = $factory->state($call['attrs']);
                        } elseif (isset($call['for']) && is_array($call['for'])) {
                            $relation = isset($call['relation']) && is_string($call['relation']) ? $call['relation'] : null;
                            $related = $this->resolveForTarget($call['for'], $model, $relation, $factories, $results);
                            $factory = $factory->for($related, $relation);
                        } else {
                            abort(422, 'Invalid factory call.');
                        }
                    }
                    if (isset($item['count'])) {
                        $factory = $factory->count((int) $item['count']);
                    }
                    $attributes = $item['attrs'] ?? [];
                    foreach ($item['refs'] ?? [] as $name => $index) {
                        $target = $definition['relations'][$name] ?? null;
                        $related = $results[$index] ?? null;
                        abort_unless($target !== null && $related instanceof Model
                            && $data['items'][$index]['model'] === $target, 422, 'Invalid factory relation reference.');
                        $relation = (new $model)->{$name}();
                        abort_unless($relation instanceof BelongsTo && is_a($related, $relation->getRelated()::class),
                            422, 'Factory relation must be a configured belongs-to relation.');
                        $attributes[$relation->getForeignKeyName()] = $related->getAttribute($relation->getOwnerKeyName());
                    }

                    return $factory->create($attributes);
                };
                $results[] = isset($configured[$item['model']]['factory'])
                    ? $this->withFactoryModel($factory, $model, $create)
                    : $create();
            }

            return $results;
        });
    }

    private function applyState(Factory $factory, array $states, string $name, array $rawArgs, array $factories): Factory
    {
        $state = $states[$name] ?? null;
        abort_unless($state !== null, 422, 'Factory state is not registered.');

        $params = $state['params'];
        abort_if(count($rawArgs) > count($params), 422, 'Too many arguments for factory state.');

        $hydrated = [];
        foreach ($params as $index => $param) {
            if (! array_key_exists($index, $rawArgs)) {
                abort_unless($param['optional'], 422, 'Missing required argument for factory state.');

                continue;
            }
            $hydrated[] = $this->hydrateArgument($rawArgs[$index], $param, $factories);
        }

        return $factory->{$state['method']}(...$hydrated);
    }

    private function hydrateArgument(mixed $raw, array $param, array $factories): mixed
    {
        if ($raw === null) {
            abort_unless($param['nullable'], 422, 'Factory state argument cannot be null.');

            return null;
        }

        $types = $param['types'];
        if (is_array($raw) && isset($raw['$rehearsalModel']) && is_string($raw['$rehearsalModel'])) {
            $modelClass = $factories[$raw['$rehearsalModel']]['model'] ?? null;
            $id = $raw['id'] ?? null;
            abort_unless($modelClass !== null && (is_int($id) || is_string($id)), 422, 'Invalid model reference.');
            foreach ($types as $type) {
                if (($type['kind'] === 'model' && is_a($modelClass, $type['class'], true)) || $type['kind'] === 'mixed') {
                    return $modelClass::query()->findOrFail($id);
                }
            }
            abort(422, 'Model reference does not match factory state parameter.');
        }

        $modelTypes = array_values(array_filter($types, static fn (array $type) => $type['kind'] === 'model'));
        $kinds = array_column($types, 'kind');
        $allowsScalarMatch = (is_string($raw) && (in_array('string', $kinds, true) || in_array('mixed', $kinds, true)))
            || (is_int($raw) && (in_array('int', $kinds, true) || in_array('float', $kinds, true) || in_array('mixed', $kinds, true)));
        if ($modelTypes !== [] && ! $allowsScalarMatch && ((is_array($raw) && isset($raw['id']) && (is_int($raw['id']) || is_string($raw['id']))) || is_int($raw) || is_string($raw))) {
            $id = is_array($raw) ? $raw['id'] : $raw;
            $concrete = array_values(array_filter($modelTypes, static fn (array $type) => ! (new ReflectionClass($type['class']))->isAbstract()));
            if (count($concrete) === 1) {
                return $concrete[0]['class']::query()->findOrFail($id);
            }
            if (is_array($raw)) {
                abort(422, 'Ambiguous model reference; pass a model created by Rehearsal.');
            }
        }

        foreach ($types as $type) {
            $kind = $type['kind'];
            if ($kind === 'mixed') {
                return $raw;
            }
            if ($kind === 'string' && is_string($raw)) {
                return $raw;
            }
            if ($kind === 'int' && is_int($raw)) {
                return $raw;
            }
            if ($kind === 'float' && (is_float($raw) || is_int($raw))) {
                return (float) $raw;
            }
            if ($kind === 'bool' && is_bool($raw)) {
                return $raw;
            }
            if ($kind === 'true' && $raw === true) {
                return true;
            }
            if ($kind === 'false' && $raw === false) {
                return false;
            }
            if ($kind === 'array' && is_array($raw)) {
                return $raw;
            }
            if ($kind === 'enum' && (is_string($raw) || is_int($raw))) {
                /** @var class-string<BackedEnum> $enumClass */
                $enumClass = $type['class'];
                $backing = (new ReflectionEnum($enumClass))->getBackingType()?->getName();
                if (($backing === 'int' && is_int($raw)) || ($backing === 'string' && is_string($raw))) {
                    $case = $enumClass::tryFrom($raw);
                    if ($case !== null) {
                        return $case;
                    }
                }
            }
            if ($kind === 'date' && is_string($raw)) {
                try {
                    return is_a($type['class'], DateTimeImmutable::class, true)
                        ? CarbonImmutable::parse($raw)
                        : Carbon::parse($raw);
                } catch (Throwable) {
                    abort(422, 'Invalid date argument for factory state.');
                }
            }
        }

        abort(422, 'Invalid factory state argument.');
    }

    private function resolveForTarget(array $for, string $model, ?string $relation, array $factories, array $results): Model
    {
        if (isset($for['ref']) && is_int($for['ref'])) {
            $related = $results[$for['ref']] ?? null;
            abort_unless($related instanceof Model, 422, 'Invalid factory relation reference.');
            $this->assertForRelationMatches($model, $relation, $related);

            return $related;
        }
        $alias = $for['$rehearsalModel'] ?? $for['model'] ?? null;
        $id = $for['id'] ?? null;
        abort_unless(is_int($id) || is_string($id), 422, 'Invalid factory for() target.');

        if (is_string($alias)) {
            $targetClass = $factories[$alias]['model'] ?? null;
            abort_unless(is_string($targetClass) && is_subclass_of($targetClass, Model::class), 422, 'Unknown model alias for factory for().');
            $resolved = $targetClass::query()->findOrFail($id);
            $this->assertForRelationMatches($model, $relation, $resolved);

            return $resolved;
        }

        if ($relation !== null && method_exists($model, $relation)) {
            $rel = (new $model)->{$relation}();
            if ($rel instanceof BelongsTo && ! $rel instanceof MorphTo) {
                return $rel->getRelated()->newQuery()->findOrFail($id);
            }
        }

        abort(422, 'Cannot resolve model for factory for(); pass a model created by Rehearsal.');
    }

    private function assertForRelationMatches(string $model, ?string $relation, Model $resolved): void
    {
        if ($relation === null || ! method_exists($model, $relation)) {
            return;
        }
        $rel = (new $model)->{$relation}();
        if ($rel instanceof BelongsTo && ! $rel instanceof MorphTo) {
            abort_unless(is_a($resolved, $rel->getRelated()::class), 422, 'Model does not match factory for() relation.');
        }
    }

    private function withFactoryModel(Factory $factory, string $model, Closure $create): mixed
    {
        $class = $factory::class;
        $property = new ReflectionProperty($class, 'modelNameResolvers');
        $previous = $property->getValue()[$class] ?? null;
        try {
            $factory->guessModelNamesUsing(static fn () => $model);

            return $create();
        } finally {
            $resolvers = $property->getValue();
            if ($previous === null) {
                unset($resolvers[$class]);
            } else {
                $resolvers[$class] = $previous;
            }
            $property->setValue(null, $resolvers);
        }
    }

    private function travel(Attempt $attempt, array $input): string
    {
        $data = Validator::validate($input, ['time' => 'required|date_format:Y-m-d\TH:i:s.v\Z']);
        Clock::store($attempt, $data['time']);

        return $data['time'];
    }

    private function artisan(array $input): array
    {
        $data = Validator::validate($input, ['name' => 'required|string', 'args' => 'present|array']);
        abort_if(array_key_exists('command', $data['args']), 422, 'Artisan arguments must not override the command name.');
        $configured = config('rehearsal.artisan', []);
        $name = $data['name'];
        $entry = is_array($configured) ? ($configured[$name] ?? null) : null;

        if (is_array($entry)) {
            $args = Validator::validate($data['args'], $entry['rules'] ?? []);
            $exit = Artisan::call($entry['command'] ?? $name, ($entry['fixed'] ?? []) + $args);

            return ['code' => $exit];
        }

        $discover = $configured === true || (is_array($configured) && ($configured['discover'] ?? false) === true);
        if (! $discover || $entry === false || ! array_key_exists($name, Artisan::all())) {
            abort(404, 'Artisan operation is not allowlisted.');
        }

        $exit = Artisan::call($name, $data['args']);

        return ['code' => $exit];
    }

    private function modelQuery(array $input): mixed
    {
        $factories = $this->factories->all($this->protocol->run);
        $data = Validator::validate($input, [
            'model' => ['required', 'string', Rule::in(array_keys($factories))],
            'action' => ['required', 'string', Rule::in(['first', 'firstOrFail', 'get', 'pluck', 'value', 'count', 'exists', 'update', 'delete'])],
            'where' => 'sometimes|array|list|max:50',
            'where.*.column' => ['required', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'where.*.operator' => ['required', 'string', Rule::in(['=', '!=', '<', '<=', '>', '>=', 'like', 'in', 'not_in', 'null', 'not_null'])],
            'where.*.value' => 'nullable',
            'orderBy' => 'sometimes|array|list|max:10',
            'orderBy.*.column' => ['required', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'orderBy.*.direction' => ['required', 'string', Rule::in(['asc', 'desc'])],
            'limit' => 'sometimes|integer|min:1|max:1000',
            'column' => ['sometimes', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'values' => 'sometimes|array|min:1|max:100',
        ]);

        $model = $factories[$data['model']]['model'] ?? '';
        abort_unless(is_subclass_of($model, Model::class), 422);

        $query = $model::query();
        foreach ($data['where'] ?? [] as $clause) {
            $column = $clause['column'];
            $operator = $clause['operator'];
            if (! in_array($operator, ['null', 'not_null'], true)) {
                abort_unless(array_key_exists('value', $clause), 422, "Filter value is required for [{$column}].");
            }
            $value = $clause['value'] ?? null;
            match ($operator) {
                'null' => $query->whereNull($column),
                'not_null' => $query->whereNotNull($column),
                'in' => $query->whereIn($column, is_array($value) ? $value : [$value]),
                'not_in' => $query->whereNotIn($column, is_array($value) ? $value : [$value]),
                default => $query->where($column, $operator, $value),
            };
        }
        foreach ($data['orderBy'] ?? [] as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }
        if (isset($data['limit'])) {
            $query->limit($data['limit']);
        }

        return match ($data['action']) {
            'first' => $query->first()?->toArray(),
            'firstOrFail' => $query->firstOrFail()->toArray(),
            'get' => $query->get()->map(static fn (Model $row) => $row->toArray())->all(),
            'pluck' => $query->pluck($data['column'] ?? abort(422, 'Column is required for pluck.'))->values()->all(),
            'value' => $query->value($data['column'] ?? abort(422, 'Column is required for value.')),
            'count' => isset($data['limit']) ? min($query->count(), (int) $data['limit']) : $query->count(),
            'exists' => $query->exists(),
            'update' => $this->updateQuery($query, $data['values'] ?? abort(422, 'Values are required for update.')),
            'delete' => $this->deleteQuery($query),
        };
    }

    private function updateQuery(\Illuminate\Database\Eloquent\Builder $query, array $values): int
    {
        foreach (array_keys($values) as $column) {
            abort_unless(is_string($column) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) === 1, 422, 'Invalid update column.');
        }

        return $query->getConnection()->transaction(static function () use ($query, $values): int {
            $count = 0;
            foreach ($query->get() as $model) {
                if ($model->forceFill($values)->save()) {
                    $count++;
                }
            }

            return $count;
        });
    }

    private function deleteQuery(\Illuminate\Database\Eloquent\Builder $query): int
    {
        return $query->getConnection()->transaction(static function () use ($query): int {
            $count = 0;
            foreach ($query->get() as $model) {
                if ($model->delete() === true) {
                    $count++;
                }
            }

            return $count;
        });
    }
}
