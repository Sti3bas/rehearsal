# Reverb <Badge type="info" text="Optional" />

The Reverb integration uses a real Laravel Reverb server with a new application for every attempt. Different tests can subscribe to the same channel name without sharing messages.

## Configure

Install Laravel Reverb in your application and configure its usual Echo client. Set the test daemon's origin in `.env.rehearsal`:

```dotenv
REHEARSAL_REVERB_ORIGIN=http://127.0.0.1:18080
```

With [integration discovery](../concepts/discovery) enabled, a valid loopback origin activates the integration. To opt in explicitly, add `'reverb' => \Sti3bas\Rehearsal\Reverb::class` to the existing integration map. Start a separate daemon from the same Laravel checkout:

```sh
APP_ENV=rehearsal REHEARSAL_REVERB_SERVER=true \
  php artisan reverb:start --host=127.0.0.1 --port=18080
```

Set `REHEARSAL_REVERB_SERVER=true` only in that daemon. The managed Laravel web server must stay separate. Keep the app on a loopback origin as well.

## In a test

Import `reverbTest as test` from `@sti3bas/rehearsal`. Before Echo connects, have the frontend use `key`, `host`, `port`, and `scheme` from `globalThis.__REHEARSAL_REVERB__` when present. The fixture injects these public settings at the frontend `baseURL`; the publisher secret stays server-side.

```ts
import { expect, reverbTest as test } from "@sti3bas/rehearsal";

test("renders a listing update", async ({ page, laravel }) => {
  await page.goto("/listings/3");
  await laravel.reverb.waitForChannel("private-listings.3");
  await laravel.reverb.broadcast("private-listings.3", "ListingUpdated", { id: 3 });
  await expect(page.getByText("Listing updated")).toBeVisible();
});
```

This assumes your app subscribes to that channel and renders the event. Pass the exact wire channel and event names. Use `laravel.reverb.subscribed(channel)` for a non-polling occupancy check or `laravel.reverb.host` for the host and port. `waitForChannel` defaults to 10 seconds; it is an alias of `waitForSubscriber`. The `reverb` fixture exposes the same instance as `laravel.reverb`.

::: tip Assert the UI, not just the publish call
The broadcaster can report that Reverb accepted the event, not that the browser handled it. Check a visible effect in the test.
:::
