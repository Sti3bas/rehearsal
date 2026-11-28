# Mocks, fakes, and callbacks

Choose the layer that needs to change. A browser or SSR mock bypasses Laravel's route, a route fake acts on a request that reaches Laravel, and an HTTP fake intercepts a request Laravel sends out. Callback routing is for real, signature-verified inbound callbacks.

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

Use this when browser and SSR requests should reach Laravel but receive a controlled response before the route handler runs. Routes are discovered by default; configure `rehearsal.fake_routes` to restrict them. Use an uppercase method and path, or pass a structural `{ url, method }` object such as the result of a Wayfinder route call. A generated route key uses `*` for path parameters; pass a concrete route object to target one URL.

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
