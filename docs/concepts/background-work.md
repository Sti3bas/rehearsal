# Queues and schedules

Managed attempts normally use the isolated database queue. Keep the queue and failed-job migrations in the app. Do not run a shared background worker against the test database; drain work explicitly in the test so errors and the effects of work are visible to your assertions.

## Run queued and scheduled work

`laravel.queue.drain({ queues, maxJobs })` runs bounded database queue work and returns processed, pending, removed, and failed counts and failed job details. Omit `queues` to use the [configured allowlist](../guide/configuration#database-and-storage). `maxJobs` defaults to 25 and accepts 1–100.

Use `laravel.queue.failed()` to inspect failed jobs for the attempt. For work dispatched after a request returns, use `drainUntil` with an assertion rather than waiting for the pending count to reach zero. This example assumes a `reports` queue, a `reports.status` operation, and a scheduled `expire-listings` command defined by the app:

```ts
await laravel.queue.drainUntil(async () => {
  expect(await laravel.operation("reports.status")).toBe("complete");
}, { queues: ["reports"], timeout: 10_000 });
await laravel.schedule.run(["expire-listings"], { drain: { queues: ["default"] } });
```

Scheduled Artisan commands registered in Laravel's `Schedule` (e.g. `routes/console.php`) are discovered automatically by default (`'schedule' => ['discover' => true]`). You can still add explicit aliases or override/exclude discovered entries (`'some:command' => false`) in `config/rehearsal.php`. The helper runs only the named tasks once, in the attempt's app and clock; it does not evaluate cron timing or start a scheduler daemon. Omit `drain` to leave queued work pending.
