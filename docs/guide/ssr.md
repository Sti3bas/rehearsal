# SSR and split frontends

For a separate local frontend, keep its URL in Playwright's `baseURL` and set the Rehearsal `laravelURL` fixture option to the Laravel origin. If Laravel runs outside the test working directory, also set `rehearsalRoot` and `laravelServer({ cwd: ... })` to its root. Both origins must be loopback HTTP.

To use FrankenPHP, put its executable on `PATH` and pass `laravelServer({ server: "frankenphp" })`. Use `server: "auto"` to prefer FrankenPHP when installed and fall back to Artisan. Omitting the option keeps the Artisan default. Rehearsal provisions and cleans up both servers the same way. FrankenPHP uses classic request mode with `public/` as the document root; `phpWorkers` applies only to Artisan. Set `host: "localhost"` if your frontend uses that hostname for Sanctum cookies; the listener still binds only to IPv4 loopback.

- **Inertia/Vite:** same-origin Laravel controllers do not need a relay. If your separate SSR process calls Laravel itself, transport the marker on the trusted server-to-server hop and pass that request's headers to `rehearsalSsrFetch` from `@sti3bas/rehearsal/ssr`. Stock Inertia SSR does not forward it automatically. Keep the marker out of page props, HTML, and hydration payloads.

## Nuxt 4

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

`rehearsal-session` selects the current slot’s dynamically named Laravel session cookie. Other entries are exact cookie names. By default the relay forwards no cookies. With the allowlist enabled, `useRehearsalFetch()` forwards those cookies from a marked loopback Nuxt request to the configured loopback Laravel origin only. It neither follows redirects nor sends the control capability. The module does not replace a Sanctum client or automatically relay upstream `Set-Cookie`; use the browser for login and CSRF initialization. See the [Nuxt example](https://github.com/Sti3bas/rehearsal/blob/main/examples/nuxt/nuxt.config.ts) and [Playwright server setup](https://github.com/Sti3bas/rehearsal/blob/main/examples/laravel/playwright.config.ts).
