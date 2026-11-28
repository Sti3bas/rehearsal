# Laravel State <Badge type="tip" text="Built in" />

Laravel State binds sessions, cache, and queues to the attempt's [database](./database). Each slot gets its own session cookie name. The integration also switches failed jobs and queue batches to that database and isolates rate-limit counters, including named limiters registered at boot.

## Configure

The shipped integration map registers `laravel-state`, so you don't need an external Redis or queue worker. Keep your app's database-backed session, cache, queue, and failed-job migrations (and the batches table if you use batching). Laravel's configured database queue and table names are preserved.

::: warning Check explicit overrides
An explicit `cache.limiter` store is left unchanged. Isolate it yourself if it doesn't use the test database. Disable the Laravel State integration only when your application can provide its own isolated state.
:::

## In a test

```ts
const user = await laravel.factory("User").create();
await laravel.login(user);
await page.goto("/dashboard");
```

To execute dispatched work, call `await laravel.queue.drain()` when you want the jobs to run, rather than starting a shared background worker. See [queues and schedules](../concepts/background-work) and [login and queue configuration](../guide/configuration).
