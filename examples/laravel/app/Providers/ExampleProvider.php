<?php

namespace App\Providers;

use App\Jobs\WriteProbe;
use App\Models\Category;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Sti3bas\Rehearsal\Operations;

final class ExampleProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->environment('playwright')) {
            return;
        }

        RateLimiter::for('probe', static fn () => Limit::perMinute(2)->by('shared-probe'));

        $this->app->make(Operations::class)->register('probe.write', [
            'value' => 'required|string|max:100',
        ], function (array $data): void {
            Storage::disk('local')->put('probe.txt', $data['value']);
            Bus::dispatch(new WriteProbe($data['value']));
        });
        Artisan::command('probe:schedule {--fail}', function (): void {
            Bus::dispatch(new WriteProbe('scheduled', (bool) $this->option('fail')));
        });
        if (config('rehearsal.scout.engine')) {
            if (config('rehearsal.scout.engine') === 'meilisearch') {
                config([
                    'scout.meilisearch.index-settings.'.User::class => [
                        'filterableAttributes' => ['email'],
                        'sortableAttributes' => ['name'],
                    ],
                    'scout.meilisearch.index-settings.users' => ['filterableAttributes' => ['name']],
                    'scout.soft_delete' => true,
                ]);
            }
            if (config('rehearsal.scout.engine') === 'typesense') {
                config([
                    'scout.prefix' => 'example_',
                    'scout.typesense.model-settings.'.Category::class.'.collection-schema' => [
                        'fields' => [['name' => 'name', 'type' => 'string']],
                    ],
                    'scout.typesense.model-settings.'.User::class.'.collection-schema' => [
                        'fields' => [
                            ['name' => 'name', 'type' => 'string'],
                            ['name' => 'category_id', 'type' => 'string', 'reference' => 'example_categories.id'],
                        ],
                    ],
                ]);
            }
            $ops = $this->app->make(Operations::class);
            $ops->register('probe.mail', ['title' => 'required|string|max:100'], function (array $data): void {
                Mail::html('<a href="https://example.test/verify?a=1&amp;b=2"><b>Verify</b> address</a>',
                    fn ($mail) => $mail->to('receiver@example.test')->subject($data['title']));
            });
            $ops->register('probe.delete-user', ['id' => 'required|integer'], fn (array $data) => User::findOrFail($data['id'])->delete());
            $ops->register('probe.search', ['name' => 'sometimes|string|max:100', 'sort' => 'sometimes|boolean'], function (array $data): array {
                $search = User::search('Probe');
                if (isset($data['name'])) {
                    $search->where('name', $data['name']);
                }
                if ($data['sort'] ?? false) {
                    $search->orderBy('name', 'desc');
                }

                return match (config('scout.driver')) {
                    'typesense' => array_map(fn ($hit) => $hit['document']['name'], $search->options(['query_by' => 'name'])->raw()['hits']),
                    'meilisearch' => array_column($search->raw()['hits'], 'name'),
                    default => $search->get()->pluck('name')->all(),
                };
            });
        }
    }
}
