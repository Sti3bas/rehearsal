# Inertia <Badge type="tip" text="Same origin" />

An Inertia app served by Laravel works with Rehearsal's standard Playwright setup. Browser navigations and Inertia visits go through the Laravel routes and controllers you already use, so the attempt cookie selects the right database, session, and other test state. No Inertia module or JavaScript adapter is required.

## Configure a Laravel-served app

Set Playwright's `baseURL` to the managed Laravel origin and use [the single-origin Playwright config](../guide/installation#run-your-first-test). Point tests at Laravel, not at the Vite asset server. Build your frontend assets or start the Vite dev server as you would for a local Laravel run; `laravelServer()` manages Laravel, not the asset pipeline.

## In a test

Use Rehearsal for fixture setup and Playwright for interactions. For example, if the app has an authenticated profile page:

```ts
import { expect, test } from "@sti3bas/rehearsal";

test("shows the current user's profile", async ({ laravel, page }) => {
  const user = await laravel.factory("User").create({ name: "Ada" });
  await laravel.login(user);
  await page.goto("/profile");
  await expect(page.getByText("Ada", { exact: true })).toBeVisible();
});
```

## What about Inertia SSR?

Laravel controller queries already run in the attempt. If the Inertia SSR process only renders the props that Laravel supplied, it does not need its own relay. Rehearsal does not provide an Inertia SSR plugin, and stock Inertia SSR does not automatically forward the attempt marker on a separate call back to Laravel.

::: warning Custom SSR data fetching
If a renderer calls Laravel directly, you must carry the marker on a trusted server-to-server hop yourself. Pass that request's headers to [`rehearsalSsrFetch`](../reference/typescript#package-subpaths) from `@sti3bas/rehearsal/ssr`. Never put the marker into Inertia props, HTML, or the hydration payload.
:::
