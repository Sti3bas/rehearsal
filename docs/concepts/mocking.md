# Mocks, fakes, and callbacks

## Which fake should I use?

| Request you want to replace | API |
| --- | --- |
| An incoming request that should reach Laravel, but not its real route handler | `laravel.fake` |
| An outbound request made through Laravel's HTTP client | `laravel.http.fake` |
| A Nuxt browser or SSR fetch that should use a live test handler | `nuxt.fake` |
| A Laravel-bound browser request or a Nuxt SSR call using `useRehearsalFetch()` | `laravel.route(...).respond(...)` |

Callback routing is for real, signature-verified inbound callbacks, not for faking responses.

## Mock Laravel routes

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

### Nuxt browser and SSR fakes

Install the `@sti3bas/rehearsal/nuxt` module and run Nuxt bound to loopback with `NUXT_REHEARSAL_ENABLED=true` in your test environment only. Use `nuxtTest` with a loopback `baseURL`, or set `nuxtURL` if `baseURL` points at Laravel. To use both fixtures, compose them with Playwright's `mergeTests(test, nuxtTest)`.

```ts
import { nuxtTest as test, passthrough } from "@sti3bas/rehearsal";

test("renders a live fake during SSR", async ({ nuxt, page }) => {
  let failing = true;
  await nuxt.fake({
    "GET https://search.example.test/collections/*": (request) =>
      failing && !new URL(request.url).searchParams.has("retry")
        ? { status: 500, json: { message: "outage" } }
        : passthrough,
  });
  await page.goto("/");
  failing = false;
});
```

Keys are HTTP methods plus absolute URLs. A `*` matches any string; the query is matched only if the pattern includes one. Give each rule a response, an array of responses (the last repeats), or a sync/async callback taking a standard `Request`. The callback can return `passthrough` to let the real request run. Newer rules win, and the browser and Nitro both consume the same sequence. Use `NuxtFakeRule<YourJsonType>` to constrain the response body for an external API. No MSW API is needed.

Use `{ networkError: true }` to simulate a connection failure instead of an HTTP error. Playwright aborts the browser request and Nitro's proxied `fetch` rejects with a `TypeError`. The failure can also be delayed with `delay`, returned by a callback, or used in a sequence.

Unmatched scripts, stylesheets, fonts, and EventSource requests use the browser's normal routing without the SSR bridge. Explicit rules can still match those requests.

Fakes belong to the fixture's Playwright page and SSR requests made by that page. `page.request`, `context.request`, and standalone `APIRequestContext` requests bypass Playwright page routing, so they do not get these fakes or transmit the SSR context. For SSR, a test-only header identifies the worker's loopback handler; Nitro removes it before calling application code, and never forwards it to the original destination. Rules are cleared between tests or manually with `nuxt.clearFakes()`. This proxy-back uses standard server-side fetch on Node/Nitro 2; requests made outside the browser's test context run normally.

Playwright page routing handles only the first request in a browser redirect chain. To test SSR fakes on a redirect destination, navigate directly to that URL; if the request reaches Laravel, a Laravel route fake also works across redirects.

Use `nuxt.hold()` to pause matching browser or SSR requests before they hit the real destination. The returned handler counts arrivals and lets the requests pass through when released. Release SSR requests before the proxy's 10-second timeout.

```ts
const pending = nuxt.hold();
await nuxt.fake({ "GET https://search.example.test/collections/*": pending });
const navigation = page.goto("/");
await expect.poll(pending.count).toBeGreaterThan(0);
pending.release();
await navigation;
await nuxt.navigate(page, "/search?q=oak", { expect: /q=oak$/ });
```

`nuxt.navigate(page, path, { state?, expect? })` runs a Nuxt router push and waits for the destination URL (or the provided string or regular expression). In an app using Vue Router, pass `state` to attach history state.

`pause()` lets matched requests continue to Laravel after `release()`; with a response it returns that response instead. `waitForRequest({ timeout: 5000 })` waits until the first match (five seconds by default). Clearing a mock removes future matches, but does not release requests already waiting on its gate.

Set `rehearsal.route_mocks` to `false` to disable browser and SSR response mocks and their resolution route. The browser login route is only installed when login is configured, and the Typesense credential route is only installed when the Scout integration is configured for Typesense.

## Fake incoming Laravel routes

```ts
await laravel.fake("GET /api/listings", [
  { status: 503, once: true },
  { json: { data: [] } },
]);
await laravel.fake({ url: "/api/listings", method: "get" }, { delay: 100 });
await laravel.fake("GET /api/listings", (request) => ({
  json: { data: [{ id: Number(new URL(request.url).searchParams.get("id")) }] },
}));
await laravel.clearFakes();
```

Use this when browser and SSR requests should reach Laravel but receive a controlled response before the route handler runs. Routes are discovered by default; configure `rehearsal.fake_routes` to restrict them. Use an uppercase method and path, or pass a structural `{ url, method }` object such as the result of a Wayfinder route call. A generated route key uses `*` for a single path segment and `**` for a catch-all that spans segments (for example, `GET /files/**`). Pass a concrete route object to target one URL.

Generate declarations with [`rehearsal:types`](./factories#generate-types-from-your-laravel-app) to catch unknown route keys. For typed JSON, set the route's TypeScript response type in `rehearsal.fake_response_types` (for example, `'GET /api/listings' => '{ data: { id: number }[] }'`). The generated key and literal route objects select that response type. If a route helper returns only `url: string`, the compiler cannot identify the endpoint; the server still checks its method and URL. Use the generated key when you need JSON checking with such a helper.

Callbacks receive a standard `Request` and run in the Playwright test process, so they can read and update test-local state. They may return a response or a promise. Use `once` or `times` to limit static responses; otherwise each sequence entry is used once except the final one, which repeats. A delay without a response lets the real route run afterward. A headers-only fake returns an empty HTTP 200 with those headers. `clearFakes()` clears incoming fake rules independently of browser mocks and outbound HTTP fakes. Each attempt starts with no rules.

When PHP exposes the original multipart body, Rehearsal forwards it unchanged. When PHP has already parsed it, callbacks receive reconstructed fields and files. Use bracketed names (for example, `tags[]`) for repeated values: PHP collapses repeated unbracketed fields before Laravel sees them. PHP's parsed data also cannot distinguish a dense list of explicitly numbered names from empty brackets, or recover the original ordering between fields and files.

## Route verified callbacks

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

## Fake outbound Laravel HTTP

```ts
await laravel.http.fake({
  "https://api.openai.com/v1/moderations*": {
    json: { results: [{ flagged: false, categories: {} }] },
  },
});
```

Rules are data only and apply to Laravel's HTTP client, not to a browser fetch or SDKs that use their own transport. A rule can specify `status`, `json` or `body`, headers, or `networkError`. To match a method or return a sequence, use `{ method: "POST", responses: [...] }`; the final response repeats. Calling `fake()` with a new map replaces the attempt's rules. Unmatched requests are blocked by default; allow them explicitly with `{ preventStrayRequests: false }` only when intentional.
