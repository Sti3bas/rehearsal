# Service integrations

| Integration | Isolation and configuration |
| --- | --- |
| Database | SQLite file snapshot, MySQL table/data clone, or PostgreSQL template clone. Set `database.seeder` for a shared baseline and `database.aliases` for additional named connections that should use the slot database. |
| Filesystem | Local and public disks are isolated by default. Set `disks` to select others. Use the disk's generated `url()` or `temporaryUrl()`, not hardcoded `/storage/…` links. |
| Cache, session, queue | Uses the slot database. Keeps the configured database queue and table names. |
| Clock | Per-attempt Laravel clock and Playwright page clock through `laravel.travel()`. |
| Mail / Mailpit | Mail logs by default. Opt in to Mailpit to inspect attempt-tagged inboxes with `laravel.mailbox(recipient)`. |
| Scout | Database search uses the slot DB. Meilisearch and Typesense use a scoped key and per-slot index names; reset clears documents without rebuilding the indexes. |
| Reverb | Each attempt has its own Reverb application, so workers can reuse channel names. Only the public browser config is injected. |

## MySQL or PostgreSQL

Set these in `.env.rehearsal` (or pass their values through `laravelServer({ database: { ... } })`):

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=rehearsal
DB_PASSWORD=your-local-test-password
REHEARSAL_DB_DISPOSABLE=true
```

For PostgreSQL use `DB_CONNECTION=pgsql` and the PostgreSQL port. The account needs permission to create and drop databases. Use a disposable loopback server, never a development or production database. MySQL templates support tables/data, not views, triggers, routines, or events.

## Mailpit, Scout, and Reverb

Add entries to the **existing** `integrations` array in the published config, keeping the defaults. Install Laravel Scout/Reverb and the selected Scout PHP client in the app when using them.

```php
// Add to the existing 'integrations' array:
'mailpit' => \Sti3bas\Rehearsal\Mailpit::class,
'scout' => \Sti3bas\Rehearsal\Scout::class,
'reverb' => \Sti3bas\Rehearsal\Reverb::class,

// Set at the top level of config/rehearsal.php:
'mailpit' => ['api' => 'http://127.0.0.1:8025', 'smtp_port' => 1025],
'scout' => [
    'engine' => 'typesense', // database, meilisearch, or typesense
    'origin' => 'http://127.0.0.1:8108',
    'admin_key' => env('REHEARSAL_SCOUT_ADMIN_KEY'),
    'models' => [\App\Models\User::class],
    'baseline_models' => [\App\Models\User::class], // optional, Typesense only
],
'reverb' => [
    'origin' => 'http://127.0.0.1:18080',
    'server' => env('REHEARSAL_REVERB_SERVER', false),
],
```

- **Scout:** Custom search/index names must honor `scout.prefix`. For remote engines, list the searchable models and configure their schemas as you normally would. The database engine needs no search container or admin key.
- **Mailpit:** The integration configures the default test mailer. Explicit app-specific mailers should never point at live SMTP during a test.
- **Reverb:** Run a separate daemon from the same checkout with `APP_ENV=rehearsal REHEARSAL_REVERB_SERVER=true php artisan reverb:start --host=127.0.0.1 --port=18080`. Import `reverbTest as test` in browser specs and configure your Echo client with the `key`, `host`, and `port` from `globalThis.__REHEARSAL_REVERB__` before it starts. The publisher secret stays server-side.

**Typesense baselines and browser search.** Seed the database baseline as usual (for example, with `rehearsal.database.seeder`), put Scout after Database in `integrations`, and list the models to reimport under `scout.baseline_models`. Rehearsal imports those models from the restored slot DB with Scout on every attempt. Without that list, collections start empty. Define schemas under Laravel Scout's `scout.typesense.model-settings.<model>.collection-schema` or via `typesenseCollectionSchema()`. For schemas with `reference` fields, list the referenced models before their dependents in `models`; Rehearsal retargets those references to the slot's collections.

Pass `laravel.typesense()` to your browser's test setup to configure direct Typesense search. It returns `{ origin, collectionPrefix, searchKey }`. Use the prefix with the model's collection suffix (for example, `${collectionPrefix}users`) and send the key as `X-TYPESENSE-API-KEY`. Enable CORS on the disposable Typesense server for browser access. The key permits only document searches in the slot's collections and is revoked at the next reset or at teardown; admin and backend write keys stay server-side.

After loading your frontend, wait for Echo to join and then publish on the attempt's real Reverb application:

```ts
await reverb.waitForSubscriber("private-listings.3");
await reverb.broadcast("private-listings.3", "ListingUpdated", { id: 3 });
await expect(page.getByText("Listing updated")).toBeVisible();
```

Both helpers use the exact wire channel and event names. Use `await reverb.subscribed(channel)` for a non-polling occupancy check. Assert frontend behavior to verify delivery; the publisher can only report whether Reverb accepted the event.

Read rendered mail from the current attempt without replacing the application's mailer with an assertion fake:

```ts
const inbox = laravel.mailbox("buyer@example.test");
await inbox.expectReceived(/verify/i);
const verifyURL = await inbox.link("Verify address", { subject: /verify/i });
await page.goto(verifyURL);
```

`inbox.latest({ subject })` waits for a matching message and returns its HTML and text. `inbox.all()` lists the recent summaries without waiting.

See [the working example config](https://github.com/Sti3bas/rehearsal/blob/main/examples/laravel/config/rehearsal.php) and [disposable services](https://github.com/Sti3bas/rehearsal/blob/main/examples/laravel/compose.integrations.yml).
