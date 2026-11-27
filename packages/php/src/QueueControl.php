<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Database\Query\Builder;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class QueueControl
{
    public static function drain(array $input): array
    {
        $options = self::queueOptions($input);
        $requested = $options['queues'];
        $jobs = DB::connection(config('queue.connections.database.connection'))
            ->table(config('queue.connections.database.table'))->whereIn('queue', $requested);
        $before = $jobs->count();
        $processed = 0;
        $failures = [];
        $running = true;
        Event::listen(JobProcessed::class, static function (JobProcessed $event) use (&$processed, &$running, $requested): void {
            if ($running && ! $event->job->hasFailed() && $event->connectionName === 'database'
                && in_array($event->job->getQueue(), $requested, true)) {
                $processed++;
            }
        });
        Event::listen(JobFailed::class, static function (JobFailed $event) use (&$failures, &$running, $requested): void {
            if ($running && $event->connectionName === 'database' && in_array($event->job->getQueue(), $requested, true)) {
                $failures[] = ['job' => $event->job->resolveName(), 'exception' => $event->exception::class];
            }
        });
        try {
            $exit = Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => implode(',', $requested),
                '--stop-when-empty' => true,
                '--max-jobs' => $options['maxJobs'],
                '--max-time' => 20,
                '--tries' => 1,
                '--sleep' => 0,
            ]);
        } finally {
            $running = false;
        }
        $pending = $jobs->count();

        return [
            'code' => $exit,
            'removed' => max(0, $before - $pending),
            'pending' => $pending,
            'processed' => $processed,
            'failed' => count($failures),
            'failures' => $failures,
        ];
    }

    public static function failed(array $input): array
    {
        return self::failures(self::queueOptions($input)['queues'])->orderByDesc('id')->limit(100)->get()
            ->map(static function (object $failure): array {
                $payload = json_decode($failure->payload, true);
                preg_match('/\A([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*):/', $failure->exception, $error);

                return [
                    'id' => $failure->uuid ?? (string) $failure->id,
                    'queue' => $failure->queue,
                    'job' => $payload['displayName'] ?? 'unknown',
                    'exception' => $error[1] ?? 'Throwable',
                    'failedAt' => $failure->failed_at,
                ];
            })->all();
    }

    public static function schedule(array $input): array
    {
        $entries = app(Schedules::class)->all();
        $data = Validator::validate($input, [
            'names' => 'required|array|list|min:1|max:10',
            'names.*' => ['required', 'string', Rule::in(array_keys($entries))],
            'drain' => 'sometimes|array:queues,maxJobs',
        ]);
        if (array_key_exists('drain', $data)) {
            self::queueOptions($data['drain']);
        }
        $results = [];
        foreach ($data['names'] as $name) {
            $entry = $entries[$name];
            $code = isset($entry['raw'])
                ? Artisan::call($entry['raw'])
                : Artisan::call($entry['command'], $entry['fixed'] ?? []);
            $results[] = ['name' => $name, 'code' => $code];
            if ($code !== 0) {
                return ['results' => $results, 'drain' => null];
            }
        }

        return ['results' => $results, 'drain' => array_key_exists('drain', $data) ? self::drain($data['drain']) : null];
    }

    private static function queueOptions(array $input): array
    {
        $queues = self::queues();
        $data = Validator::validate($input, [
            'queues' => 'sometimes|array|list|min:1|max:10',
            'queues.*' => ['required', 'string', Rule::in($queues)],
            'maxJobs' => 'sometimes|integer|min:1|max:100',
        ]);

        return $data + ['queues' => $queues, 'maxJobs' => 25];
    }

    /** @return list<string> */
    public static function queues(): array
    {
        $configured = config('rehearsal.queues');
        if (is_array($configured) && $configured !== []) {
            return array_values(array_unique(array_filter($configured, 'is_string')));
        }

        $queues = [];
        $push = static function (mixed $value) use (&$queues): void {
            foreach ((array) $value as $entry) {
                if (! is_string($entry)) {
                    continue;
                }
                foreach (explode(',', $entry) as $name) {
                    $name = trim($name);
                    if ($name !== '') {
                        $queues[] = $name;
                    }
                }
            }
        };

        $push(config('queue.connections.database.queue', 'default'));

        $collect = static function (mixed $supervisors) use ($push): void {
            if (! is_array($supervisors)) {
                return;
            }
            foreach ($supervisors as $supervisor) {
                if (! is_array($supervisor) || ! isset($supervisor['queue'])) {
                    continue;
                }
                $push($supervisor['queue']);
            }
        };

        $collect(config('horizon.defaults', []));
        foreach ((array) config('horizon.environments', []) as $environment) {
            $collect($environment);
        }

        if ($queues === []) {
            $queues[] = 'default';
        }

        return array_values(array_unique($queues));
    }

    private static function failures(array $queues): Builder
    {
        return DB::connection(config('queue.failed.database'))->table(config('queue.failed.table'))
            ->where('connection', 'database')->whereIn('queue', $queues);
    }
}
