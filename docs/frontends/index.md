# Frontends

Choose the guide that matches how your app reaches Laravel. Same-origin browser requests carry the attempt cookie. Server-to-server requests need a trusted relay if they must reach the same attempt.

<DocGrid :items="[
  { title: 'Inertia', href: '/frontends/inertia', badge: 'Same origin', description: 'Use the ordinary Laravel browser flow. No adapter required for Inertia pages served from Laravel.' },
  { title: 'Nuxt', href: '/frontends/nuxt', badge: 'Nuxt 4 module', description: 'Relay the current attempt through SSR and opt into cookie forwarding or direct Typesense search.' },
]" />

For Blade or other Laravel-rendered pages, use the [single-origin setup](../guide/installation#run-your-first-test). The browser's base URL and Laravel's URL are the same.

## Use two origins for a split frontend

When the browser opens a separate frontend, set Playwright's `baseURL` to that frontend. Set Rehearsal's `laravelURL` to Laravel, and launch both servers. If tests run outside the Laravel root, set both `rehearsalRoot` and `laravelServer({ cwd })`.

```ts{7-9,12} [playwright.config.ts]
import { defineConfig } from "@playwright/test";
import { laravelServer } from "@sti3bas/rehearsal";

export default defineConfig({
  use: {
    browserName: "chromium",
    baseURL: "http://127.0.0.1:3000",
    laravelURL: "http://127.0.0.1:8100",
    rehearsalRoot: "../backend",
  },
  webServer: [
    laravelServer({ cwd: "../backend" }),
    { command: "npm run dev -- --host 127.0.0.1 --port 3000", url: "http://127.0.0.1:3000/api/ready" },
  ],
});
```

This example assumes a frontend `dev` script and a lightweight `/api/ready` endpoint that returns success without querying Laravel. Adapt both to your app. Both services must be loopback HTTP. Use the same hostname on both ports when your browser must share cookies.

::: tip Browser requests and SSR are different
If a frontend server makes its own Laravel request, it does not automatically inherit the browser's attempt marker. Use the [Nuxt module](./nuxt) or a trusted loopback relay with [`rehearsalSsrFetch`](../reference/typescript#package-subpaths) for custom SSR. Do not cache, prerender, or serialize attempt markers into page data.
:::

## Choose a Laravel server

The default is `artisan`. To use FrankenPHP, put the executable on `PATH` and pass `laravelServer({ server: "frankenphp" })`. Use `"auto"` to prefer FrankenPHP when available and fall back to Artisan. FrankenPHP uses classic request mode with `public/` as the document root. `phpWorkers` applies only to Artisan. Setting `host: "localhost"` changes the advertised hostname; the listener still binds to IPv4 loopback.
