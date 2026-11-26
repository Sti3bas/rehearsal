# Rehearsal

Parallel Playwright testing for Laravel.

Rehearsal is built from scratch. Its Laravel–Playwright bridge and dedicated test environment take inspiration from [Saucebase Laravel Playwright](https://github.com/sauce-base/laravel-playwright).

## Milestone 1

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
- Custom integrations implement `Sti3bas\Rehearsal\Integration` and are registered in `config/rehearsal.php`. The registry calls them in configuration order and cleans them up in reverse. Named operations register with `Sti3bas\Rehearsal\Operations`; validate inputs and keep handlers test-only.

Rehearsal currently supports a classic per-request Laravel runtime. Separate SSR propagation, persistent worker runtimes, Mailpit, Scout/Typesense, and Reverb are outside milestone 1.

Licensed under the MIT License.
