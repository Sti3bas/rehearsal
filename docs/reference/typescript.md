# TypeScript API

Install `@sti3bas/rehearsal` alongside `@playwright/test`. Import `test` and `expect` from Rehearsal in specs that need Laravel state; the returned test instance supports standard Playwright configuration, fixtures, and assertions.

## Fixtures and exports

| Export from `@sti3bas/rehearsal` | Use |
| --- | --- |
| `test` | Playwright Test with an automatic per-test `laravel` fixture, attempt-bound browser contexts, and prepared pages. Service workers are blocked by default. |
| `reverbTest` | Extended test with a `reverb` fixture and public Reverb config injected at the frontend `baseURL`. Requires the Reverb integration. |
| `expect` | Playwright's assertion API. |
| `Laravel` | Client class behind the `laravel` fixture. Prefer using the fixture instead of constructing the client. |
| `defineOperation<Input, Output>(name)` | Names a server operation with explicit TypeScript input and output types. Does not add a server handler. |
| `defineRehearsal({ operations })` | Extends `test` with an `operations` fixture for those definitions. |
| `generateRehearsalTypes({ path, root?, environment?, database? })` | Runs the Artisan type generator. `root` defaults to the current working directory; `path` is relative to it unless absolute. |
| `injectReverb(context, baseURL, metadata)` | Installs public Reverb client settings on a custom context. The `reverbTest` fixture already calls it. |

The package exports the `RehearsalSchema` interface for generated module augmentation, as well as public factory, model query, HTTP fake, route response, operation, and integration data types.

### Secondary browser sessions

Use the `newBrowser` fixture to open another browser context in the same Laravel attempt without sharing cookies with the primary context:

```ts
test("another user can see the change", async ({ laravel, newBrowser }) => {
  const user = await laravel.create("User");
  const { page } = await newBrowser({
    user,
    context: { userAgent: "Secondary browser" },
    page: true,
  });
  await page.goto("/");
});
```

Each call inherits Playwright's context options; `context` overrides them for that call. Omit `user` to skip Rehearsal login, or pass the ID of an existing user. Omit `page` to get just the context. If `page: true`, the page follows `laravel.travel()`. Rehearsal closes these contexts when the test ends. With `reverbTest`, the secondary context also receives the attempt's Reverb settings.

### Playwright `use` options

| Option | Default | Meaning |
| --- | --- | --- |
| `baseURL` | Playwright default | Frontend origin. Also used as the Laravel origin when no `laravelURL` is supplied. |
| `laravelURL` | Unset | Laravel's loopback HTTP origin when the frontend runs separately. |
| `rehearsalRoot` | Current working directory | Laravel root where the worker reads the managed launch record. |
| `rehearsalCapability` | Unset | Advanced override for an already provisioned loopback run; omit it for managed runs. Never send this value to a browser. |

## laravelServer

`laravelServer(options?)` returns a Playwright `webServer` entry. It uses the dedicated dotenv file, provisions the baseline, waits on the bridge readiness endpoint, and does not reuse an existing server. The readiness timeout is 120 seconds.

| Option | Default | Meaning |
| --- | --- | --- |
| `port` | `8100` | Integer from 1024 to 65535. Match the Laravel origin used by Playwright. |
| `host` | `"127.0.0.1"` | `"127.0.0.1"` or `"localhost"`; the listener binds to IPv4 loopback. |
| `cwd` | Current working directory | Laravel application root. |
| `server` | `"artisan"` | `"artisan"`, `"frankenphp"`, or `"auto"` (prefer FrankenPHP when available). |
| `phpWorkers` | `8` | Integer from 2 to 64. Applies to the Artisan server. |
| `environment` | `"rehearsal"` | Laravel environment name and suffix of the required `.env.<environment>`. Must also be allowlisted. |
| `env` | `{}` | Explicit application environment overrides. Undefined entries are omitted; the launcher owns isolation variables. |
| `database` | `{}` | Managed overrides: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, `REHEARSAL_DB_DISPOSABLE`. |
| `integrations` | `{}` | Managed integration environment overrides with names like `REHEARSAL_INTEGRATION_REPORTS`. Integrations must read and interpret their own variables. |

## Laravel fixture methods

| Call | Result or side effect |
| --- | --- |
| `factory(alias)` | [Fluent factory builder](../concepts/factories); use `.create()` to persist. |
| `factory(alias, attrs, options?)` | Creates one row, or an array when `options.count` is set. |
| `factory(items)` | Creates a batch and returns results in the same order. |
| `create(alias, { state?, attrs? }?)` | Shorthand for creating a single row. |
| `model(alias)` | [Eloquent model query builder](../concepts/model-queries). |
| `call(name, input?)` | Named operation with generated input/output types. A required input remains required by the generated type. |
| `operation<T>(name, data?)` | Dynamic named operation call with an optional manually selected result type. |
| `artisan(name, args?)` | Runs an available Artisan command or alias and returns `{ code }`. |
| `login({ id }, context?)` | Signs a user into an attempt-bound browser context and returns the authenticated ID. |
| `travel(date)` | Sets the attempt clock and fixed time in prepared pages; accepts a Date or date string. |
| `bindContext(context)` / `preparePage(page)` | Attach attempt cookie and route behavior to an extra context; track an extra page for the fixture clock. The built-in fixtures do this automatically. |
| `route(target).respond(response)` / `.pause(response?)` | Installs a mock or a gate. Pause returns `waitForRequest({ timeout }?)` and `release()`. |
| `fake(target, responseOrSequenceOrCallback)` | Fakes incoming Laravel traffic by generated method/path key or a `{ url, method }` route object. Callbacks run in the test process. |
| `clearRouteMocks()` / `clearFakes()` | Clears future matches of the corresponding kind. |
| `http.fake(rules, options?)` | Replaces outgoing Laravel HTTP fake rules; blocks stray requests by default. |
| `queue.drain({ queues?, maxJobs? }?)` | Returns `{ code, processed, removed, pending, failed, failures }`. |
| `queue.failed({ queues? }?)` | Lists failed jobs with IDs, names, queues, exceptions, and failure times. |
| `queue.drainUntil(assertion, options?)` | Drains and retries the assertion until it succeeds, the queue work fails, or the timeout expires (default 10 s; interval 100 ms). |
| `schedule.run(names, { drain? }?)` | Runs an alias or a list of aliases, optionally drains queues, and returns `{ results, drain }`. Throws on a nonzero task exit code. |
| `mailbox(recipient)` | Attempt-scoped inbox with `all()`, `latest(filter?)`, `expectReceived(subject?, options?)`, and `link(text, filter?)`. Subject and link text accept strings or regular expressions. |
| `typesense()` | Browser-safe `{ origin, collectionPrefix, searchKey }` for the attempt; requires the Typesense integration. Use `.collection(name)` for the prefixed `name`, relative `searchPath`, and `waitForIndexed(count, { timeout }?)` (at least `count` hits). |
| `user<T>({ context, endpoint, frontendOrigin }?)` | Reads the signed-in user from the bound browser context (or an explicit context). Returns parsed JSON or `null` on HTTP 401. Defaults to `/api/user`; override with the `rehearsalUserEndpoint` test option or a same-origin endpoint per call. For Sanctum, `frontendOrigin` defaults to the test's `baseURL` origin; override it if the frontend runs on a different origin. |
| `integrationMetadata(name)` | Client metadata published by an integration, typed as `unknown`. Do not publish server secrets through integration metadata. |

Route responses accept `status`, `json` or `body`, `headers`, `delay` (milliseconds), and `once` or `times`. Browser/SSR mocks also support `abort`, `networkError`, and `passthrough`. See [mocks and callbacks](../concepts/mocking) for matching and gate behavior.

`laravel.reverb.host` is the attempt's Reverb host and port, `.subscribed(channel)` checks occupancy, `.waitForChannel(channel, { timeout }?)` waits for occupancy, and `.broadcast(channel, event, payload?)` publishes a message. `reverbTest` exposes the same API through its `reverb` fixture and injects the browser connection config. See [Reverb](../integrations/reverb) for setup.

## Package subpaths

| Import | API |
| --- | --- |
| `@sti3bas/rehearsal/ssr` | `rehearsalSsrFetch({ enabled, laravelOrigin, requestHeaders })` creates a fetch bound to the configured Laravel origin. `attemptMarker(headers)` extracts a marker of the expected format; Laravel verifies its signature. |
| `@sti3bas/rehearsal/nuxt` | Nuxt 4 module. Options under `rehearsal`: `laravelOrigin` (default `http://127.0.0.1:8100`), `forwardCookies` (default `[]`), and `typesense` (default `false`). Auto-imports `useRehearsalFetch` and `useTypesenseInstantSearch`. |
| `@sti3bas/rehearsal/reporter` | Playwright reporter for the reset timings attached to attempts. Add it alongside your regular reporters. |

See [Frontends](../frontends/) and [Nuxt](../frontends/nuxt) for relay and process settings, or [CI and performance](../guide/ci) for reporter configuration.
