<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
            'travel' => $this->travel($attempt, $data),
            'artisan' => $this->artisan($data),
            'queue.drain' => QueueControl::drain($data),
            'queue.failed' => QueueControl::failed($data),
            'schedule.run' => QueueControl::schedule($data),
            'route.mock' => RouteMocks::register($attempt, $data, false),
            'route.mock.clear' => RouteMocks::clear($attempt, false),
            'route.mock.release' => RouteMocks::release($attempt, $data),
            'route.fault' => RouteMocks::register($attempt, $data, true),
            'route.fault.clear' => RouteMocks::clear($attempt, true),
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
        abort_unless(is_array($options), 404, 'Rehearsal login is not enabled.');
        $input = $request->validate(['id' => 'required']);
        abort_unless(is_int($input['id']) || is_string($input['id']), 422);
        $guard = Auth::guard($options['guard'] ?? 'web');
        abort_unless($guard->loginUsingId($input['id']), 404);
        $request->session()->regenerate();

        return response()->json(['data' => $guard->id()]);
    }

    private function factory(array $input): array
    {
        $factories = $this->factories->all($this->protocol->run);
        $data = Validator::validate($input, [
            'items' => 'required|array|list|min:1|max:100',
            'items.*' => 'required|array:model,attrs,states,count,refs',
            'items.*.model' => ['required', 'string', Rule::in(array_keys($factories))],
            'items.*.attrs' => 'sometimes|array|max:100',
            'items.*.states' => 'sometimes|array|list|max:10',
            'items.*.states.*' => 'required|string',
            'items.*.count' => 'sometimes|integer|min:1|max:100',
            'items.*.refs' => 'sometimes|array|max:10',
            'items.*.refs.*' => 'required|integer|min:0',
        ]);
        abort_if(array_sum(array_map(fn ($item) => $item['count'] ?? 1, $data['items'])) > 100, 422);

        return DB::transaction(function () use ($data, $factories): array {
            $results = [];
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
                foreach ($item['states'] ?? [] as $state) {
                    $method = $definition['states'][$state] ?? null;
                    abort_unless(is_string($method), 422, 'Factory state is not registered.');
                    $factory = $factory->{$method}();
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
                $results[] = $factory->create($attributes);
            }

            return $results;
        });
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
        $entry = config('rehearsal.artisan')[$data['name']] ?? null;
        abort_unless(is_array($entry), 404, 'Artisan operation is not allowlisted.');
        $args = Validator::validate($data['args'], $entry['rules'] ?? []);
        $exit = Artisan::call($entry['command'], ($entry['fixed'] ?? []) + $args);

        return ['code' => $exit];
    }
}
