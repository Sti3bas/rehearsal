<div align="center">
  <img src="assets/banner.png" width="100%" alt="Rehearsal: four Playwright workers passing tests under theatre spotlights">
  <h1><img src="assets/logo.svg" width="320" alt="Rehearsal"></h1>
  <p><strong>Parallel Playwright testing for Laravel</strong></p>
  <p>
    <a href="https://github.com/Sti3bas/rehearsal/actions/workflows/ci.yml"><img src="https://github.com/Sti3bas/rehearsal/actions/workflows/ci.yml/badge.svg?branch=main" alt="CI"></a>
    <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green.svg" alt="License: MIT"></a>
    <img src="https://img.shields.io/badge/PHP-8.3%2B-777bb4" alt="PHP 8.3+">
    <img src="https://img.shields.io/badge/Laravel-13-ff4433" alt="Laravel 13">
  </p>
</div>

Rehearsal joins a real Laravel app to native Playwright Test. A worker can create users, sign in, send mail, or fill a search index without affecting another worker doing the same thing. You keep Playwright's browser APIs, traces, retries, and reporters; Rehearsal provisions and resets the Laravel state behind them.

- **Run in parallel:** isolated databases, files, sessions, cache, and queues with one Laravel HTTP server.
- **Arrange tests from TypeScript:** Laravel model factories, registered states, browser login, time travel, queue drains, and named app operations.
- **Test real services:** opt in to Mailpit, Scout (database, Meilisearch, or Typesense), and Reverb.
- **Bring your own setup:** add PHP integrations, relay routing through Nuxt SSR, and report reset timings by integration.

## Quickstart

**Requirements:** Laravel 13, PHP 8.3+ with the matching PDO extension, Node 22.18+ in the 22 line or Node 24 LTS, and Playwright Test 1.65.1 or compatible. The managed server uses PHP CLI workers (Linux/macOS). Use a classic Laravel request lifecycle, not Octane or another persistent application runtime.

**Release status:** The packages are not published yet. The commands below are the installation path once they are available. To try the current branch, use [the runnable starter](#run-from-source).

```sh
composer require --dev sti3bas/rehearsal
npm install -D @sti3bas/rehearsal @playwright/test
php artisan rehearsal:install
npx playwright install chromium
```

The installer writes `.env.playwright` without overwriting an existing file. Ignore `.env.playwright` and `.rehearsal/`. SQLite is the default. Keep Laravel's migrations for the database-backed sessions, cache, and queues you use (including the failed-jobs table for queue drains).

**`playwright.config.ts`**

```ts
import { defineConfig } from "@playwright/test";
import { laravelServer } from "@sti3bas/rehearsal";

export default defineConfig({
  fullyParallel: true,
  workers: 4,
  use: { baseURL: "http://127.0.0.1:8100" },
  webServer: laravelServer(),
});
```

**`tests/app.spec.ts`**

```ts
import { expect, test } from "@sti3bas/rehearsal";

test("creates a user in this test's database", async ({ laravel, page }) => {
  const user = await laravel.factory("User", {
    name: "Ada",
    email: "ada@example.test",
  });

  expect(user.name).toBe("Ada");
  expect((await page.goto("/"))?.ok()).toBe(true);
});
```

This assumes the standard `App\Models\User` factory and a reachable root page. Run `npx playwright test`. To customize the package, publish `config/rehearsal.php`:

```sh
php artisan vendor:publish --tag=rehearsal-config
```

### Fixture API

| Call | What it does |
| --- | --- |
| `laravel.factory("User", attrs, { states, count })` | Creates a model or a batch. The model and state aliases must be registered in config. Pass an array of definitions to batch several factories into one call. |
| `laravel.login({ id })` | Logs in the current browser context through the configured guard. Opt in with `'login' => ['guard' => 'web']`. |
| `laravel.travel("2034-01-02T12:00:00.000Z")` | Sets Laravel's test clock and the fixed time on pages managed by the fixture. |
| `laravel.artisan("about", {})` | Runs a configured alias, never an arbitrary Artisan command. |
| `laravel.operation("reports.stage", input)` | Calls a named, validated application operation. |

#### Mock Laravel routes

```ts
await laravel.route("/api/listings/**").respond({ json: { data: [] }, delay: 100 });
await laravel.route(listings.show({ listing: slug })).respond({ status: 404, once: true });
```

Pass a URL at the Laravel origin, a path (with `*` for a segment or `**` across segments), or a Wayfinder object or route function and its parameters. Wayfinder objects also match the HTTP method. Supports JSON, text bodies, headers, status, delays (milliseconds), browser aborts and network errors. If a pattern includes a query string it is matched; otherwise queries are ignored. Newest matching registration wins; `once` is shared between browser and SSR, including across browser contexts bound to the attempt. The Nuxt module uses MSW for fetches using `useRehearsalFetch()` in forwarding mode. Browser routes apply only to Playwright contexts bound to the attempt and HTTP requests to Laravel, not third-party assets.

For traffic that must actually reach Laravel, allowlist paths or glob patterns in `rehearsal.fault_paths`, then use `await laravel.route("/api/listings/**").fault({ status: 503, once: true })`. A delay without a response lets the real route run afterward. Each attempt starts with no rules.

#### Fake outbound Laravel HTTP

```ts
await laravel.http.fake({
  "https://api.openai.com/v1/moderations*": {
    json: { results: [{ flagged: false, categories: {} }] },
  },
});
```

Rules are data only and apply to Laravel's HTTP client, not to a browser fetch or SDKs that use their own transport. A rule can specify `status`, `json` or `body`, headers, or `networkError`. To match a method or return a sequence, use `{ method: "POST", responses: [...] }`; the final response repeats. Calling `fake()` with a new map replaces the attempt's rules. Unmatched requests are blocked by default; allow them explicitly with `{ preventStrayRequests: false }` only when intentional.

#### Run queued and scheduled work

`laravel.queue.drain({ queues, maxJobs })` runs bounded database queue work and returns processed, pending, removed, and failed counts and failed job details. Omit `queues` to use the configured allowlist, which defaults to Laravel's database queue name.

Use `laravel.queue.failed()` to inspect failed jobs for the attempt. For work dispatched after a request returns, use `drainUntil` with an assertion rather than waiting for the pending count to reach zero:

```ts
await laravel.queue.drainUntil(async () => {
  expect(await laravel.operation("reports.status")).toBe("complete");
}, { queues: ["reports"], timeout: 10_000 });
await laravel.schedule.run(["expire-listings"], { drain: { queues: ["default"] } });
```

In `config/rehearsal.php`, register scheduled commands as
`'schedule' => ['expire-listings' => ['command' => 'listings:expire']]`.
An entry may include trusted `fixed` Artisan arguments. The helper runs only the named tasks once, in the attempt's app and clock; it does not evaluate cron timing or start a scheduler daemon. Omit `drain` to leave queued work pending.

### How isolation works

- A **run** is one managed Playwright server lifecycle. It owns the baseline and resources created during that run.
- A **slot** is a stable worker lane, derived from Playwright's `parallelIndex + 1`. A replacement worker can reuse the slot.
- An **attempt** is one test execution, including each retry. Before the test begins, Rehearsal resets the slot and grants a fresh signed routing marker. Requests carrying a retired marker are rejected.

The lifecycle is **provision → reset → bind requests → cleanup**. Rehearsal copies or clones the migrated database baseline rather than running migrations for every test.

## Built-in integrations

| Integration | Isolation and configuration |
| --- | --- |
| Database | SQLite file snapshot, MySQL table/data clone, or PostgreSQL template clone. Set `database.seeder` for a shared baseline and `database.aliases` for additional named connections that should use the slot database. |
| Filesystem | Local and public disks are isolated by default. Set `disks` to select others. Use the disk's generated `url()` or `temporaryUrl()`, not hardcoded `/storage/…` links. |
| Cache, session, queue | Uses the slot database. Keeps the configured database queue and table names. |
| Clock | Per-attempt Laravel clock and Playwright page clock through `laravel.travel()`. |
| Mail / Mailpit | Mail logs by default. Opt in to Mailpit to inspect attempt-tagged inboxes with `laravel.mailbox(recipient)`. |
| Scout | Database search uses the slot DB. Meilisearch and Typesense use a scoped key and per-slot index names; reset clears documents without rebuilding the indexes. |
| Reverb | Each attempt has its own Reverb application, so workers can reuse channel names. Only the public browser config is injected. |

### MySQL or PostgreSQL

Set these in `.env.playwright` (or pass their values through `laravelServer({ database: { ... } })`):

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=rehearsal
DB_PASSWORD=your-local-test-password
REHEARSAL_DB_DISPOSABLE=true
```

For PostgreSQL use `DB_CONNECTION=pgsql` and the PostgreSQL port. The account needs permission to create and drop databases. Use a disposable loopback server, never a development or production database. MySQL templates support tables/data, not views, triggers, routines, or events.

### Mailpit, Scout, and Reverb

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
],
'reverb' => [
    'origin' => 'http://127.0.0.1:18080',
    'server' => env('REHEARSAL_REVERB_SERVER', false),
],
```

- **Scout:** Custom search/index names must honor `scout.prefix`. For remote engines, list the searchable models and configure their index/collection schema as you normally would. Remote indexes start empty; index baseline records in a named operation if a test needs them. The database engine needs no search container or admin key.
- **Mailpit:** The integration configures the default test mailer. Explicit app-specific mailers should never point at live SMTP during a test.
- **Reverb:** Run a separate daemon from the same checkout with `APP_ENV=playwright REHEARSAL_REVERB_SERVER=true php artisan reverb:start --host=127.0.0.1 --port=18080`. Import `reverbTest as test` in browser specs and configure your Echo client with the `key`, `host`, and `port` from `globalThis.__REHEARSAL_REVERB__` before it starts. The publisher secret stays server-side.

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

See [the working example config](examples/laravel/config/rehearsal.php) and [disposable services](examples/laravel/compose.integrations.yml).

### Write an integration

An integration implements four lifecycle hooks. Register the class under a unique key in `rehearsal.integrations`. The registry resolves it through Laravel's container in configuration order, then cleans up in reverse.

```php
namespace App\Testing;

use Illuminate\Support\Facades\File;
use Sti3bas\Rehearsal\{Attempt, Integration, Run};

final class ReportScratch implements Integration
{
    public function provision(Run $run): void {}

    public function reset(Attempt $attempt): void
    {
        $path = $attempt->path('reports');
        if (is_dir($path) && ! File::deleteDirectory($path)) {
            throw new \RuntimeException('Cannot clear the report scratch directory.');
        }

        File::ensureDirectoryExists($path, 0700);
    }

    public function bind(Attempt $attempt): void
    {
        config(['reports.scratch_path' => $attempt->path('reports')]);
    }

    public function cleanup(Run $run): void {}
}
```

Add `'reports' => \App\Testing\ReportScratch::class` to the integration map. The app can now use `reports.scratch_path`. These files live inside the run directory, which the managed launcher removes. If your integration creates resources outside that directory, record what it owns and remove only those resources during cleanup.

### Register named operations

Keep fixture setup that knows about your application in an application service provider. Validate inputs and use the app's own methods rather than adding a generic command endpoint.

```php
use Illuminate\Support\Facades\Storage;
use Sti3bas\Rehearsal\Operations;

// In a service provider's boot() method:
if ($this->app->environment('playwright')) {
    $this->app->make(Operations::class)->register(
        'reports.stage',
        ['title' => ['required', 'string', 'max:100']],
        fn (array $data) => Storage::disk('local')->put('report.txt', $data['title']),
    );
}
```

```ts
await laravel.operation("reports.stage", { title: "April summary" });
```

For purpose-built Artisan wrappers, add aliases under `rehearsal.artisan` with a `command`, optional `fixed` arguments, and validation `rules` for arguments the caller may supply.

## SSR and split frontends

For a separate local frontend, keep its URL in Playwright's `baseURL` and set the Rehearsal `laravelURL` fixture option to the Laravel origin. If Laravel runs outside the test working directory, also set `rehearsalRoot` and `laravelServer({ cwd: ... })` to its root. Both origins must be loopback HTTP.

- **Inertia/Vite:** same-origin Laravel controllers do not need a relay. If your separate SSR process calls Laravel itself, transport the marker on the trusted server-to-server hop and pass that request's headers to `rehearsalSsrFetch` from `@sti3bas/rehearsal/ssr`. Stock Inertia SSR does not forward it automatically. Keep the marker out of page props, HTML, and hydration payloads.

### Nuxt 4

Add the module to `nuxt.config.ts`; no plugin files to copy. If your package manager doesn't expose Nuxt's `@nuxt/kit` to peer dependencies, install `@nuxt/kit@^4` alongside the npm package.

```ts
export default defineNuxtConfig({
  modules: ["@sti3bas/rehearsal/nuxt"],
});
```

Opt your Laravel requests into the relay with the auto-imported `useRehearsalFetch()`:

```ts
const { data } = await useFetch("/api/users", {
  baseURL: apiBase, // your existing Laravel API URL
  $fetch: useRehearsalFetch(),
});
```

For direct calls, use `const fetchLaravel = useRehearsalFetch()` in setup and call it like `$fetch`. Set `NUXT_REHEARSAL_ENABLED=true` **only** in the Nuxt process launched by Playwright. The test relay defaults to `http://127.0.0.1:8100`; if Playwright launches Laravel on a different port, set `NUXT_REHEARSAL_LARAVEL_ORIGIN` in the same process. In the browser or while disabled, the composable returns Nuxt’s ordinary `$fetch`, so keep your app’s usual URL or `baseURL`.

For Sanctum SPA auth, use the same loopback hostname on different ports. Add the frontend host and port to Sanctum’s stateful domains, enable stateful API middleware and configure Laravel CORS for the frontend origin with credentials enabled. In the browser, request `/sanctum/csrf-cookie` with credentials and send the decoded XSRF cookie in `X-XSRF-TOKEN` on writes. Rehearsal lets Laravel handle cookie-less CORS preflights on configured paths without binding an attempt. Actual requests still require the attempt marker; Laravel stores sessions in the isolated slot database.

For authenticated SSR, opt in to forwarding just the browser cookies your app needs:

```ts
export default defineNuxtConfig({
  modules: ["@sti3bas/rehearsal/nuxt"],
  rehearsal: { forwardCookies: ["rehearsal-session", "XSRF-TOKEN"] },
});
```

`rehearsal-session` selects the current slot’s dynamically named Laravel session cookie. Other entries are exact cookie names. By default the relay forwards no cookies. With the allowlist enabled, `useRehearsalFetch()` forwards those cookies from a marked loopback Nuxt request to the configured loopback Laravel origin only. It neither follows redirects nor sends the control capability. The module does not replace a Sanctum client or automatically relay upstream `Set-Cookie`; use the browser for login and CSRF initialization. See the [Nuxt example](examples/nuxt/nuxt.config.ts) and [Playwright server setup](examples/laravel/playwright.config.ts).

## CI and performance

After installing app dependencies in CI, run:

```yaml
- run: php artisan rehearsal:install
- run: npx playwright install --with-deps chromium
- run: npx playwright test --workers=4
```

The [full workflow](.github/workflows/ci.yml) exercises all three DB engines at one and four workers, plus all opt-in services. To see reset costs, add the reporter next to your regular reporter:

```ts
reporter: [["line"], ["@sti3bas/rehearsal/reporter"]]
```

It reports mean/max reset times and the number of measured attempts, including retries. Start with a small worker count and measure on your runner. SQLite file copies are a simple starting point; if a suite needs MySQL or PostgreSQL behavior, test against that engine rather than swapping SQL semantics for speed.

### Run from source

```sh
composer install --working-dir=examples/laravel
(cd examples/laravel && php artisan rehearsal:install)
npm ci
npm run build
npx playwright install chromium --only-shell
npm run e2e
```

Add `REHEARSAL_E2E_WORKERS=1` for a serial run. The optional services can be exercised locally:

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

## Security model

Rehearsal is **test-only**. The bridge activates only in the `playwright` environment with valid managed run settings, and the managed launcher listens on loopback. Keep it off public networks and use disposable databases and service credentials. Never publish the launch record or `.env.playwright`.

The browser's signed, HTTP-only cookie routes ordinary app requests to the test attempt. Cookies do not distinguish ports, so run only trusted loopback services on that host. The separate control capability is not sent in browser navigation or forwarded by SSR. Rehearsal does not expose arbitrary PHP, SQL, shell commands, or unrestricted Artisan execution.

## When to use something else

- **Laravel Dusk** or **Pest browser testing** are natural choices if you want to write browser specs in PHP alongside your Laravel tests.
- **Playwright alone** is enough when you already provision isolated state outside the test runner, or tests only use public interfaces and don't need fixture setup.
- **Rehearsal** is for teams that want native Playwright Test in TypeScript and need Laravel state reset and optional integrations per parallel worker.

## License

[Composer package](packages/php/README.md) · [npm package](packages/ts/README.md) · [MIT License](LICENSE) · [Logo](assets/logo.svg) · [Mark](assets/mark.svg)
