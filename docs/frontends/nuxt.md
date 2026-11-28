# Nuxt <Badge type="tip" text="Nuxt 4 module" />

## Quickstart: Nuxt and Laravel

This example assumes siblings named `backend/` (Laravel 13) and `frontend/` (Nuxt 4 with Node and Nitro 2). Put Playwright in `frontend/`.

```sh
# backend/
composer require --dev sti3bas/rehearsal
php artisan rehearsal:install

# frontend/
npm install -D @sti3bas/rehearsal @playwright/test
npx playwright install chromium
```

Add the module to your existing Nuxt config. If your package manager doesn't expose Nuxt's `@nuxt/kit` to peer dependencies, install `@nuxt/kit@^4` alongside the npm package.

```ts [frontend/nuxt.config.ts]
export default defineNuxtConfig({
  modules: ["@sti3bas/rehearsal/nuxt"],
});
```

Enable the relay **only** in the dedicated loopback Nuxt process started by Playwright. Use the same hostname on both ports.

```ts [frontend/playwright.config.ts]
import { defineConfig } from "@playwright/test";
import { laravelServer } from "@sti3bas/rehearsal";

const frontend = "http://127.0.0.1:3000";
const backend = "http://127.0.0.1:8100";

export default defineConfig({
  testDir: "./tests",
  use: { browserName: "chromium", baseURL: frontend, laravelURL: backend, rehearsalRoot: "../backend" },
  webServer: [
    laravelServer({ cwd: "../backend", port: 8100 }),
    {
      command: "npm run dev -- --host 127.0.0.1 --port 3000",
      url: frontend + "/api/ready",
      timeout: 120_000,
      env: {
        NUXT_REHEARSAL_ENABLED: "true",
        NUXT_REHEARSAL_LARAVEL_ORIGIN: backend,
      },
    },
  ],
});
```

Use a readiness route that doesn't need a Laravel request, so that Playwright can start even if the SSR relay isn't configured yet:

```ts [frontend/server/api/ready.get.ts]
export default defineEventHandler(() => ({ ready: true }));
```

If your project still uses the starter's `<NuxtWelcome />`, replace `frontend/app/app.vue` with a root component that renders your pages:

```vue [frontend/app/app.vue]
<template>
  <NuxtPage />
</template>
```

Add a smoke page that fetches once on the server and fetches again on a click. The example URL stands in for an API dependency; the test supplies both responses.

```vue [frontend/app/pages/rehearsal-smoke.vue]
<script setup lang="ts">
const { data, refresh } = await useAsyncData("rehearsal-smoke", () =>
  $fetch<{ message: string }>("https://example.test/message"),
);
const mounted = ref(false);
onMounted(() => { mounted.value = true; });
</script>

<template>
  <p>{{ data?.message }}</p>
  <button :disabled="!mounted" @click="refresh()">Refresh</button>
</template>
```

```ts [frontend/tests/rehearsal.spec.ts]
import { mergeTests } from "@playwright/test";
import { expect, nuxtTest, test } from "@sti3bas/rehearsal";

const browserTest = mergeTests(test, nuxtTest);

browserTest("fakes the server render and a browser refresh", async ({ nuxt, page }) => {
  const headers = { "access-control-allow-origin": "http://127.0.0.1:3000" };
  await nuxt.fake({
    "GET https://example.test/message": [
      { json: { message: "From SSR" } },
      { json: { message: "From the browser" }, headers },
    ],
  });
  const response = await page.goto("/rehearsal-smoke");
  expect(await response!.text()).toContain("<p>From SSR</p>");
  await page.getByRole("button", { name: "Refresh" }).click();
  await expect(page.getByText("From the browser")).toBeVisible();
});
```

Run `npx playwright test` from `frontend/`. The first browser-and-SSR registration checks that the module is loaded, the relay is enabled, and Nitro supports interception. If you use a different server URL for the Nuxt fixture, set its `nuxtURL` option.

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

With the module enabled on a Node Nitro 2 server, a route such as `server/api/sitemap.get.ts` can call `$fetch("/api/sitemap", { baseURL: apiBase })` or use an imported `ofetch` normally. Rehearsal forwards the incoming attempt marker only to the configured Laravel origin and does not follow redirects on that hop. It does not copy incoming cookies or capabilities. Automatic forwarding is not available on Nitro 3. Use `useRehearsalFetch()` for [opt-in session cookie forwarding](#sanctum-and-authenticated-ssr), not as a requirement for `nuxt.fake`.

## Fake browser and SSR requests

With the module installed and `NUXT_REHEARSAL_ENABLED=true` on the loopback Nuxt server, merge the fixtures with `mergeTests(test, nuxtTest)`. The Nuxt fixture uses the frontend `baseURL` by default; set the `nuxtURL` test option if your `baseURL` points to Laravel.

The SSR readiness check tries the configured frontend path and its ancestors, from most specific to root. For an unusual mount, set `nuxtBase` to your Nuxt app's base path in Playwright's `use` options (or call `browserTest.use({ nuxtBase: "/store/" })`). That probes only `/store/_rehearsal/nuxt/ready` at the frontend origin. Use `nuxtBase: "/"` to force the root.

```ts
import { mergeTests } from "@playwright/test";
import { nuxtTest, test } from "@sti3bas/rehearsal";

const browserTest = mergeTests(test, nuxtTest);

browserTest("renders search results", async ({ nuxt, page }) => {
  await nuxt.fake({
    "GET https://search.example.test/collections/*": { json: { hits: [] } },
  });
  await page.goto("/");
  await nuxt.navigate(page, "/search?q=oak", { expect: /q=oak$/ });
});
```

Prefer `nuxt.fake` for Nuxt browser and SSR fetches. It shares handlers defined in the test without putting rules in cookies. `laravel.route(...).respond(...)` is still available for Laravel-only browser interception and SSR calls through `useRehearsalFetch()`. Use `laravel.fake` instead when the request should reach Laravel before it is replaced.

Use `nuxt.hold()` as a fake handler to pause matching requests in the browser or SSR. Wait for its `count()` to increase, then call `release()` to let those requests continue. Release SSR requests before the proxy's 10-second timeout. `nuxt.navigate(page, path, { state?, expect? })` uses the client router without reloading the document and waits for the destination URL. Vue Router apps can pass `state` to attach history state. See [the mocking guide](../concepts/mocking#nuxt-browser-and-ssr-fakes) for sequences, live callbacks, and passthrough.

To hold client requests without blocking SSR, use `{ scope: "browser" }`. This scope works without the Nuxt module and leaves server requests alone. Use `*` in place of the method to match any HTTP method:

```ts
const pending = nuxt.hold();
await nuxt.fake({ "* https://search.example.test/collections/*": pending }, { scope: "browser" });
```

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
