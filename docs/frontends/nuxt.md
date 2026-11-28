# Nuxt <Badge type="tip" text="Nuxt 4 module" />

For Nuxt 4, Rehearsal provides a dedicated module for marked SSR requests and optional direct Typesense search. Configure [both Playwright origins](./#use-two-origins-for-a-split-frontend) first.

## Install the module

Add the module to `nuxt.config.ts`; no plugin files to copy. If your package manager doesn't expose Nuxt's `@nuxt/kit` to peer dependencies, install `@nuxt/kit@^4` alongside the npm package.

```ts
export default defineNuxtConfig({
  modules: ["@sti3bas/rehearsal/nuxt"],
});
```

## Enable the relay in Playwright

Set `NUXT_REHEARSAL_ENABLED=true` **only** in the Nuxt process launched by Playwright. The test relay defaults to `http://127.0.0.1:8100`; set `NUXT_REHEARSAL_LARAVEL_ORIGIN` if you use another Laravel origin.

```ts [Nuxt webServer entry]
{
  command: "npm run dev -- --host 127.0.0.1 --port 3000",
  url: "http://127.0.0.1:3000/api/ready",
  env: {
    NUXT_REHEARSAL_ENABLED: "true",
    NUXT_REHEARSAL_LARAVEL_ORIGIN: "http://127.0.0.1:8100",
  },
}
```

Use an unmarked readiness route that does not call Laravel, such as the [example `/api/ready` handler](https://github.com/Sti3bas/rehearsal/blob/main/examples/nuxt/server/api/ready.get.ts). Keep this entry alongside `laravelServer()`.

## Relay Laravel requests

Opt your Laravel requests into the relay with the auto-imported `useRehearsalFetch()`:

```ts
const { data } = await useFetch("/api/users", {
  baseURL: apiBase, // your existing Laravel API URL
  credentials: "include",
  $fetch: useRehearsalFetch(),
});
```

For direct calls, use `const fetchLaravel = useRehearsalFetch()` in setup and call it like `$fetch`. In the browser or while disabled, the composable returns Nuxt’s ordinary `$fetch`, so keep your app’s usual URL or `baseURL`.

::: warning Browser calls still need credentials
For direct calls from the Nuxt browser app to Laravel on another port, set `credentials: "include"` (or `withCredentials: true` if you use Axios) and [allow the Nuxt origin in Laravel CORS](./#send-credentials-from-the-browser). This is required for the attempt cookie even on unauthenticated routes. It does not enable SSR cookie forwarding.
:::

## Nitro routes

With the module enabled on a Node Nitro 2 server, a route such as `server/api/sitemap.get.ts` can call `$fetch("/api/sitemap", { baseURL: apiBase })` or use an imported `ofetch` normally. Rehearsal forwards the incoming attempt marker only to the configured Laravel origin and does not follow redirects on that hop. It does not copy incoming cookies or capabilities. Automatic forwarding is not available on Nitro 3. For SSR mocks and opt-in cookie forwarding, keep using `useRehearsalFetch()` in Nuxt app code.

## Typesense InstantSearch

Enable `NUXT_REHEARSAL_TYPESENSE=true` in the Nuxt test process when [Laravel's Typesense integration](../integrations/scout/typesense) is enabled. Use the auto-imported adapter helper where your app constructs its normal search client:

```ts
import TypesenseInstantsearchAdapter from "typesense-instantsearch-adapter";

const { configure, indexName } = useTypesenseInstantSearch();
const { searchClient } = new TypesenseInstantsearchAdapter(configure(appSearchOptions));
const listingsIndex = indexName("listings");
```

Use `listingsIndex` for the InstantSearch index; pass collection names without the Rehearsal prefix to `indexName()`. In tests, the helper swaps in the slot's Typesense node and scoped search key, and prefixes collection-specific adapter settings. Nuxt fetches those credentials from Laravel using the signed attempt marker and hydrates only the search configuration, not the control capability. Outside a marked test request the helper preserves the app's normal options and index names, with no test branch in app code. Do not prerender or cache attempt-bound pages, and allow the loopback Nuxt origin in the disposable Typesense server's CORS configuration.

## Sanctum and authenticated SSR

For Sanctum SPA auth, use the same loopback hostname on different ports. Add the frontend host and port to Sanctum’s stateful domains, enable stateful API middleware, and include `sanctum/csrf-cookie` and any cross-origin login routes in Laravel's CORS paths. In the browser, request `/sanctum/csrf-cookie` with credentials and send the decoded XSRF cookie in `X-XSRF-TOKEN` on writes. Laravel stores sessions in the isolated slot database.

For authenticated SSR, opt in to forwarding just the browser cookies your app needs:

```ts
export default defineNuxtConfig({
  modules: ["@sti3bas/rehearsal/nuxt"],
  rehearsal: { forwardCookies: ["rehearsal-session", "XSRF-TOKEN"] },
});
```

`rehearsal-session` selects the current slot’s dynamically named Laravel session cookie. Other entries are exact cookie names. By default the relay forwards no cookies. With the allowlist enabled, `useRehearsalFetch()` forwards those cookies from a marked loopback Nuxt request to the configured loopback Laravel origin only. It neither follows redirects nor sends the control capability. The module does not replace a Sanctum client or automatically relay upstream `Set-Cookie`; use the browser for login and CSRF initialization. See the [Nuxt example](https://github.com/Sti3bas/rehearsal/blob/main/examples/nuxt/nuxt.config.ts) and [Playwright server setup](https://github.com/Sti3bas/rehearsal/blob/main/examples/laravel/playwright.config.ts).
