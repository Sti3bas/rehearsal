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

**Release status:** The packages are not published yet. Use [a Git revision](#install-from-git) or [the runnable starter](#run-from-source) until the commands below are available.

```sh
composer require --dev sti3bas/rehearsal
npm install -D @sti3bas/rehearsal @playwright/test
php artisan rehearsal:install
npx playwright install chromium
```

The installer writes `.env.playwright` without overwriting an existing file. Ignore `.env.playwright` and `.rehearsal/`. SQLite is the default. Keep Laravel's migrations for the database-backed sessions, cache, and queues you use (including the failed-jobs table for queue drains).

### Install from Git

Add the Rehearsal repository to the Laravel application's Composer repositories, then pin both development dependencies to the same full Git SHA. Replace `<branch>` with the branch containing the revision (for example, `main`) and `<commit>` with the full SHA.

```sh
composer config repositories.rehearsal vcs https://github.com/Sti3bas/rehearsal.git
composer require --dev 'sti3bas/rehearsal:dev-<branch>#<commit>'
npm install -D 'git+https://github.com/Sti3bas/rehearsal.git#<commit>' @playwright/test
```

Composer resolves the development branch's metadata but locks the exact commit. npm builds the TypeScript package during Git installation. If GitHub access requires credentials, configure Composer's GitHub OAuth and Git authentication outside the dependency manifests and lockfiles. Then run the Artisan and Playwright install commands above.

The PHP package allows only the `testing` environment by default. Set `rehearsal.environments` in your application's config or use the comma-separated `REHEARSAL_ENVIRONMENTS` variable. New installer-generated files opt in automatically; for an existing `.env.playwright`, add `REHEARSAL_ENVIRONMENTS=playwright` or configure the allowlist in your app. To use a different environment, run `php artisan rehearsal:install --environment=browser-tests` and set `laravelServer({ environment: "browser-tests" })`. The example app opts into `playwright` explicitly.

To keep an app's existing config value instead of applying a Rehearsal default, set that dotted key to `null` in `rehearsal.safe_defaults`, for example `'safe_defaults' => ['cache.default' => null]`. Supported keys are `session.driver`, `cache.default`, `queue.default`, `mail.default`, `broadcasting.default`, `scout.driver`, and `app.debug`. Integrations still take ownership of their drivers when they bind an attempt: for example, set `'laravel-state' => false` in `rehearsal.integrations` if the app supplies its own session, cache, and queue isolation, or register a custom integration instead. Opt out only with disposable services or independently isolated state.

The Laravel state integration stores rate-limit counters in the attempt database, including when an app registers named limiters at boot. An explicit `cache.limiter` store is left unchanged; isolate that store separately if needed.

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
| `laravel.login({ id }, context?)` | Logs in the current browser context through the configured guard. Pass another browser context to bind it to the same attempt and log it in separately. Opt in with `'login' => ['guard' => 'web']`. |
| `laravel.travel("2034-01-02T12:00:00.000Z")` | Sets Laravel's test clock and the fixed time on pages managed by the fixture. |
| `laravel.artisan("about", {})` | Runs a configured alias, never an arbitrary Artisan command. |
| `laravel.operation("reports.stage", input)` | Calls a named, validated application operation. |

#### Extend the fixtures with app operations

Register operations on the Laravel side using `Operations::register($name, $rules, $handler)`. Unregistered names are rejected; the TypeScript declarations do not replace server-side input validation.

```ts
import { defineOperation, defineRehearsal } from "@sti3bas/rehearsal";

const rehearsal = defineRehearsal({
  operations: {
    stageReport: defineOperation<{ slug: string }, { id: number }>("reports.stage"),
  },
});

export const test = rehearsal.extend<{ reportId: number }>({
  reportId: async ({ operations }, use) => {
    const { id } = await operations.stageReport({ slug: "weekly" });
    await use(id);
  },
});
```

This is a Playwright test instance: compose it with ordinary fixtures for app-specific setup and fakes. To isolate a service on the Laravel side, register an app class implementing `Integration` under `rehearsal.integrations`. Its implementation and seed data stay in the consuming app.

#### Mock Laravel routes


```ts
await laravel.route("/api/listings/**").respond({ json: { data: [] }, delay: 100 });
await laravel.route(listings.show({ listing: slug })).respond({ status: 404, once: true });
await laravel.route("/api/feed").respond({ status: 503, times: 2 });

const gate = await laravel.route("/api/feed").pause({ json: { data: [] } });
const navigation = page.goto("/feed");
await gate.waitForRequest();
await gate.release();
await navigation;
await laravel.clearRouteMocks();
```

Pass a URL at the Laravel origin, a path (with `*` for a segment or `**` across segments), or a Wayfinder object or route function and its parameters. Wayfinder objects also match the HTTP method. Supports JSON, text bodies, headers, status, delays (milliseconds), aborts and network errors. If a pattern includes a query string it is matched; otherwise queries are ignored. Newest matching registration wins; `once` or `times` counts claims across the browser and SSR, including across browser contexts bound to the same attempt. Once exhausted, the next matching rule or the real route takes over. The Nuxt module uses MSW for fetches using `useRehearsalFetch()` in forwarding mode. Browser routes apply only to Playwright contexts bound to the attempt and HTTP requests to Laravel, not third-party assets.

`pause()` lets matched requests continue to Laravel after `release()`; with a response it returns that response instead. `waitForRequest({ timeout: 5000 })` waits until the first match (five seconds by default). Clearing a mock removes future matches, but does not release requests already waiting on its gate.

For traffic that must actually reach Laravel, allowlist paths in `rehearsal.fault_paths`, then use `await laravel.route("/api/listings").fault({ status: 503, once: true })`. A delay without a response lets the real route run afterward. A headers-only fault returns an empty HTTP 200 with those headers instead of running the route. Faults support `once` and `times`; use `clearRouteFaults()` to remove them independently of mocks. Each attempt starts with no rules.

Set `rehearsal.route_mocks` to `false` to disable browser and SSR response mocks and their resolution route. Real-server faults have a separate allowlist. The browser login route is only installed when login is configured, and the Typesense credential route is only installed when the Scout integration is configured for Typesense.

#### Route verified callbacks

External providers cannot send the browser's attempt marker. For a provider that
forwards webhooks to the local test server, opt in with
`'callbacks' => ['/provider/callback' => ProviderCallbackReference::class]`.
Implement `CallbackReference::verifiedReference(Request $request): ?string` in
the app. Verify the provider signature against the raw body before returning a
stable external reference, such as a provider customer ID. Do not derive the
attempt from an unsigned request field or a worker number.

When a marked app request creates that external resource, associate it with
`app(Callbacks::class)->bind('/provider/callback', $reference)`. Rehearsal saves a
signed binding scoped to the exact path, the run, and the current attempt.
Only loopback POSTs on the configured path are eligible. Valid callbacks without
a registered reference return 204, and a binding to a replaced attempt is
rejected. The app still owns its webhook route and provider-specific behavior.

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
    'baseline_models' => [\App\Models\User::class], // optional, Typesense only
],
'reverb' => [
    'origin' => 'http://127.0.0.1:18080',
    'server' => env('REHEARSAL_REVERB_SERVER', false),
],
```

- **Scout:** Custom search/index names must honor `scout.prefix`. For remote engines, list the searchable models and configure their schemas as you normally would. The database engine needs no search container or admin key.
- **Mailpit:** The integration configures the default test mailer. Explicit app-specific mailers should never point at live SMTP during a test.
- **Reverb:** Run a separate daemon from the same checkout with `APP_ENV=playwright REHEARSAL_REVERB_SERVER=true php artisan reverb:start --host=127.0.0.1 --port=18080`. Import `reverbTest as test` in browser specs and configure your Echo client with the `key`, `host`, and `port` from `globalThis.__REHEARSAL_REVERB__` before it starts. The publisher secret stays server-side.

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

Alternatively, add `'reports.stage' => ['rules' => ['title' => 'required|string|max:100'], 'handler' => StageReport::class]` to `rehearsal.operations`. The package resolves the invokable class only when the operation is called and passes it the validated array. Both registration methods share the same operation names, so a name cannot be registered twice.

For purpose-built Artisan wrappers, add aliases under `rehearsal.artisan` with a `command`, optional `fixed` arguments, and validation `rules` for arguments the caller may supply.

## SSR and split frontends

For a separate local frontend, keep its URL in Playwright's `baseURL` and set the Rehearsal `laravelURL` fixture option to the Laravel origin. If Laravel runs outside the test working directory, also set `rehearsalRoot` and `laravelServer({ cwd: ... })` to its root. Both origins must be loopback HTTP.

To use FrankenPHP, put its executable on `PATH` and pass `laravelServer({ server: "frankenphp" })`. Use `server: "auto"` to prefer FrankenPHP when installed and fall back to Artisan. Omitting the option keeps the Artisan default. Rehearsal provisions and cleans up both servers the same way. FrankenPHP uses classic request mode with `public/` as the document root; `phpWorkers` applies only to Artisan. Set `host: "localhost"` if your frontend uses that hostname for Sanctum cookies; the listener still binds only to IPv4 loopback.

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

**Nitro routes.** With the module enabled on a Node Nitro 2 server, a route such as `server/api/sitemap.get.ts` can call `$fetch("/api/sitemap", { baseURL: apiBase })` or use an imported `ofetch` normally. Rehearsal forwards the incoming attempt marker only to the configured Laravel origin and does not follow redirects on that hop. It does not copy incoming cookies or capabilities. Automatic forwarding is not available on Nitro 3. For SSR mocks and opt-in cookie forwarding, keep using `useRehearsalFetch()` in Nuxt app code.

**Typesense InstantSearch.** Enable `NUXT_REHEARSAL_TYPESENSE=true` in the Nuxt test process when Laravel's Typesense integration is enabled. Use the auto-imported adapter helper where your app constructs its normal search client:

```ts
import TypesenseInstantsearchAdapter from "typesense-instantsearch-adapter";

const { configure, indexName } = useTypesenseInstantSearch();
const { searchClient } = new TypesenseInstantsearchAdapter(configure(appSearchOptions));
const listingsIndex = indexName("listings");
```

Use `listingsIndex` for the InstantSearch index; pass collection names without the Rehearsal prefix to `indexName()`. In tests, the helper swaps in the slot's Typesense node and scoped search key, and prefixes collection-specific adapter settings. Nuxt fetches those credentials from Laravel using the signed attempt marker and hydrates only the search configuration, not the control capability. Outside a marked test request the helper preserves the app's normal options and index names, with no test branch in app code. Do not prerender or cache attempt-bound pages, and allow the loopback Nuxt origin in the disposable Typesense server's CORS configuration.

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

It reports mean/max reset times and the number of measured attempts, including retries. Start with a small worker count and measure on your runner. SQLite file copies are a simple starting point; if a suite needs MySQL or PostgreSQL behavior, test against that engine rather than swapping SQL semantics for speed. When concurrent requests in one SQLite attempt run transactions that read before writing, set `rehearsal.database.sqlite_transaction_mode` to `IMMEDIATE` on PHP 8.4+ to let writers wait at transaction start instead of failing to upgrade a read snapshot. The default is `DEFERRED`; `EXCLUSIVE` is also accepted.

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

The example Typesense container binds to loopback and allows browser requests only from `http://127.0.0.1:8100` by default. If you use a different frontend origin, set `REHEARSAL_TYPESENSE_CORS_ORIGINS` to the expected origin (or comma-separated origins) when starting Compose. The example's public admin key is for this disposable container only; never reuse it or expose the service outside loopback.

## Security model

Rehearsal is **test-only**. The bridge activates only in an allowlisted environment with valid managed run settings, and the managed launcher listens on loopback. Keep it off public networks and use disposable databases and service credentials. Never publish the launch record or your test environment file.

The browser's signed, HTTP-only cookie routes ordinary app requests to the test attempt. Cookies do not distinguish ports, so run only trusted loopback services on that host. The separate control capability is not sent in browser navigation or forwarded by SSR. Rehearsal does not expose arbitrary PHP, SQL, shell commands, or unrestricted Artisan execution.

## When to use something else

- **Laravel Dusk** or **Pest browser testing** are natural choices if you want to write browser specs in PHP alongside your Laravel tests.
- **Playwright alone** is enough when you already provision isolated state outside the test runner, or tests only use public interfaces and don't need fixture setup.
- **Rehearsal** is for teams that want native Playwright Test in TypeScript and need Laravel state reset and optional integrations per parallel worker.

## License

[Composer package](packages/php/README.md) · [npm package](packages/ts/README.md) · [MIT License](LICENSE) · [Logo](assets/logo.svg) · [Mark](assets/mark.svg)
