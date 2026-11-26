<?php

namespace App\Providers;

use App\Jobs\WriteProbe;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Sti3bas\Rehearsal\Operations;
use Sti3bas\Rehearsal\Attempt;
use Sti3bas\Rehearsal\Mailpit;

final class ExampleProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->environment('playwright')) {
            return;
        }

        $this->app->make(Operations::class)->register('probe.write', [
            'value' => 'required|string|max:100',
        ], function (array $data): void {
            Storage::disk('local')->put('probe.txt', $data['value']);
            Bus::dispatch(new WriteProbe($data['value']));
        });
        if (config('rehearsal.scout.engine')) {
            if (config('rehearsal.scout.engine') === 'meilisearch') {
                config([
                    'scout.meilisearch.index-settings.users' => ['filterableAttributes' => ['name']],
                    'scout.soft_delete' => true,
                ]);
            }
            $ops = $this->app->make(Operations::class);
            $ops->register('probe.mail', ['title' => 'required|string|max:100'], function (array $data): void {
                Mail::raw('Message content', fn ($mail) => $mail->to('receiver@example.test')->subject($data['title']));
            });
            $ops->register('probe.inbox', [], fn () => array_column(app(Mailpit::class)->messages(app(Attempt::class)), 'Subject'));
            $ops->register('probe.delete-user', ['id' => 'required|integer'], fn (array $data) => User::findOrFail($data['id'])->delete());
            $ops->register('probe.search', ['name' => 'sometimes|string|max:100'], function (array $data): array {
                $search = User::search('Probe');
                if (isset($data['name'])) {
                    $search->where('name', $data['name']);
                }

                return match (config('scout.driver')) {
                    'typesense' => array_map(fn ($hit) => $hit['document']['name'], $search->options(['query_by' => 'name'])->raw()['hits']),
                    'meilisearch' => array_column($search->raw()['hits'], 'name'),
                    default => $search->get()->pluck('name')->all(),
                };
            });
        }
        if (config('rehearsal.integrations.reverb')) {
            $this->app->make(Operations::class)->register('probe.broadcast', ['value' => 'required|string|max:100'],
                fn (array $data) => Broadcast::connection()->broadcast(['probe'], 'ping', $data));
        }
    }
}
