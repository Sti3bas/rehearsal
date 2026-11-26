# Rehearsal

Parallel Playwright testing for Laravel.

Rehearsal is built from scratch. Its Laravel–Playwright bridge and dedicated test environment take inspiration from [Saucebase Laravel Playwright](https://github.com/sauce-base/laravel-playwright).

## Run the starter

The packages are not published yet. The monorepo starter is runnable on Laravel 13, PHP 8.3+, and Node 22.18+ or 24 LTS. SQLite needs no background services. MySQL 8.4 and PostgreSQL 17 need disposable local servers and the matching PDO extension.

```sh
composer install --working-dir=examples/laravel
(cd examples/laravel && php artisan rehearsal:install)
npm ci
npm run build
npx playwright install chromium --only-shell
npm run e2e
```

The example exercises the same IDs, cache keys, disk paths and job queues in different slots. To select a server database, set `REHEARSAL_E2E_ENGINE=mysql` or `pgsql`; the starter defaults to loopback ports 13386 and 15438 respectively. It expects the disposable credentials defined in the CI workflow, never an existing developer database. To run sequentially, set `REHEARSAL_E2E_WORKERS=1`.

## App setup

When published, install the Composer and npm packages as dev dependencies: `sti3bas/rehearsal` and `@sti3bas/rehearsal`. Run `php artisan rehearsal:install` once and ignore `.env.playwright` and `.rehearsal/`. Set Playwright's `baseURL` to `http://127.0.0.1:8100`, set `webServer: laravelServer()`, and import `test` and `expect` from `@sti3bas/rehearsal`.

- The Laravel bridge supports factory batches and registered states, opt-in session login, time travel, bounded database queue drains and named operations.
- Publish config with `php artisan vendor:publish --tag=rehearsal-config` to select integrations, factory states, a baseline seeder or Artisan aliases.
- For MySQL/PostgreSQL set `DB_CONNECTION`, the loopback host/port and the disposable credentials in `.env.playwright`, plus `REHEARSAL_DB_DISPOSABLE=true`. The account needs permission to create and drop databases. Do not use a server with development or production data. MySQL baselines support tables and data, not views, triggers, routines or events.
- Named connections are disabled unless they match the selected driver or are listed in `rehearsal.database.aliases`. Allowed aliases share the same isolated database and transaction.
- Generate test asset URLs with the disk's `url()` or `temporaryUrl()`; hardcoded `/storage/…` URLs still point at the application's public symlink, not the isolated disks.
- Custom integrations implement `Sti3bas\Rehearsal\Integration` and are registered in `config/rehearsal.php`. The registry calls them in configuration order and cleans them up in reverse. Named operations register with `Sti3bas\Rehearsal\Operations`; validate inputs and keep handlers test-only.

Rehearsal supports a classic per-request Laravel runtime, not persistent Laravel workers. Browser routing uses an HTTP-only cookie on the trusted loopback host. Cookies do not distinguish ports, so run only trusted local services on that host. The separate control capability is never sent by the browser or SSR adapter.

## Optional integrations

Register the classes under `rehearsal.integrations` in the order they depend on one another (database before Scout). See [the example config](examples/laravel/config/rehearsal.php) for a working setup.

- **Mailpit** (`Mailpit::class`): set `mailpit.api` and `mailpit.smtp_port` for a disposable loopback instance. The default test mailer captures messages with an attempt tag. Use `Mailpit::messages($attempt)` inside an app-owned named operation to inspect that attempt's inbox. Do not point app-specific SMTP mailers at live services.
- **Scout** (`Scout::class`): install Laravel Scout and select `scout.engine`: `database`, `meilisearch` or `typesense`. For remote engines install the corresponding PHP client, set `scout.origin` and `scout.admin_key` for a disposable loopback instance, and list the searchable classes in `scout.models`. Custom search/index names must honor `scout.prefix`. Rehearsal uses a per-slot app key restricted to that slot's indexes and clears documents without rebuilding index schemas/settings. The first attempt starts with empty remote indexes; use app-owned setup to index any baseline records. Scout indexing is dispatched synchronously rather than via Laravel queues.
- **Reverb** (`Reverb::class` registered as `reverb`): install Laravel Reverb and set `reverb.origin`. Start a **separate test-only daemon** from the same Laravel checkout with `APP_ENV=playwright REHEARSAL_REVERB_SERVER=true php artisan reverb:start --host=127.0.0.1 --port=18080`. Import `reverbTest as test` to inject `globalThis.__REHEARSAL_REVERB__` before your Echo client initializes. Apply its public `key`, `host`, and `port` to the client; publisher secrets stay server-side. Workers can reuse channel names because each attempt gets its own Reverb app.

From the repository root, after starter setup:

```sh
export REHEARSAL_UID="$(id -u)" REHEARSAL_GID="$(id -g)"
docker compose -f examples/laravel/compose.integrations.yml up --detach --build
for engine in database meilisearch typesense; do
  REHEARSAL_E2E_SCOUT="$engine" REHEARSAL_E2E_WORKERS=4 npm run e2e -- services.spec.ts
done
REHEARSAL_E2E_REVERB=true REHEARSAL_E2E_WORKERS=4 npm run e2e -- reverb.spec.ts
REHEARSAL_E2E_NUXT_PORT=8325 REHEARSAL_E2E_WORKERS=4 npm run e2e -- ssr.spec.ts
docker compose -f examples/laravel/compose.integrations.yml down
```

### SSR

Copy the [Nuxt server plugin](examples/nuxt/app/plugins/rehearsal.server.ts) and set the corresponding private runtime config. Enable it only for E2E. It relays the attempt marker to the configured loopback Laravel origin; it does not forward session cookies or follow redirects. Use the injected fetch for server-side Laravel calls. Do not put the marker in page props, HTML or Nuxt payloads.

Inertia's same-origin controllers do not need a relay. If an Inertia/Vite SSR process fetches Laravel itself, carry the marker on a trusted server-to-server request outside the Inertia page payload. Then use `rehearsalSsrFetch` from `@sti3bas/rehearsal/ssr` with that request's headers. Stock Inertia SSR does not copy the browser's marker to its rendering process.

### Timing

Add `["@sti3bas/rehearsal/reporter"]` alongside your usual Playwright reporters. It prints mean and maximum reset times per integration and the number of measured attempts, including retries.

Licensed under the MIT License.
