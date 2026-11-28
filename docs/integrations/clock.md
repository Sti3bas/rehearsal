# Clock <Badge type="tip" text="Built in" />

The Clock integration binds Laravel's test clock to the current attempt. The Playwright fixture applies the same instant as fixed time on pages it manages. Travel to a future date to test an expiry, or align browser and Laravel dates for a time-sensitive assertion.

## Configure

`clock` is registered in the shipped integration map. No service or separate environment variables are needed. The stored time is cleared before the next attempt.

## In a test

```ts
await laravel.travel("2034-01-02T12:00:00.000Z");
await page.goto("/subscription");
```

Use a Date or a string with an explicit offset to avoid ambiguity. If you create a page outside the normal Playwright fixture, use `laravel.preparePage(page)` to include it. This sets the browser's fixed time, not a full simulation of timers or a scheduler run.

::: tip Testing scheduled work?
After setting the clock, call [`laravel.schedule.run()`](../concepts/background-work) to run the named task on demand. Travel does not start a scheduler daemon.
:::
