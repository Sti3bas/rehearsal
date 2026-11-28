# Filesystem <Badge type="tip" text="Built in" />

The Filesystem integration points selected Laravel storage disks at local test roots. Rehearsal clears those roots on reset, so an upload or generated report from one test does not appear in another attempt.

## Configure

`filesystem` is registered in the default integration map. The selected disks default to `local` and `public`; edit the top-level `disks` key in `config/rehearsal.php` to include another disk:

```php
'disks' => ['local', 'public', 'reports'],
```

Selected disks are redirected to local storage even if the normal app uses a remote driver. Unlisted disks are not isolated by this integration.

## In a test

Exercise the app's upload flow in the browser and assert the resulting link or download. For example, if the app renders a download link after submitting a document:

```ts
await page.getByLabel("Document").setInputFiles("tests/fixtures/report.pdf");
await page.getByRole("button", { name: "Upload" }).click();
await expect(page.getByRole("link", { name: "Download report" })).toBeVisible();
```

::: tip Generate URLs from the disk
In Laravel, use the disk's `url()` or `temporaryUrl()` instead of constructing a `/storage/…` URL. This lets Laravel use the isolated disk and the correct managed origin.
:::

For test-only storage assertions that are not visible in the browser, use a [named operation](../concepts/operations) calling Laravel's Storage facade.
