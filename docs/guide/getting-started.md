# Getting started

## Install in Laravel and in your Playwright project

**Requirements:** Laravel 13, PHP 8.3+ with the matching PDO extension, Node 22.18+ in the 22 line or Node 24 LTS, and Playwright Test 1.65.1 or compatible. The managed server uses PHP CLI workers (Linux/macOS). Use a classic Laravel request lifecycle, not Octane or another persistent application runtime.

Install Rehearsal as a development dependency from [Packagist](https://packagist.org/packages/sti3bas/rehearsal) and [npm](https://www.npmjs.com/package/@sti3bas/rehearsal):

```sh
composer require --dev sti3bas/rehearsal
npm install -D @sti3bas/rehearsal @playwright/test
php artisan rehearsal:install
npx playwright install chromium
```

The installer writes a disposable `.env.rehearsal` without overwriting an existing file. Ignore local env files containing secrets and `.rehearsal/`. SQLite is the default. Keep Laravel's migrations for the database-backed sessions, cache, and queues you use (including the failed-jobs table for queue drains).

No changes to `bootstrap/app.php` are needed. The launcher sets `APP_ENV=rehearsal` on every managed process, so Laravel loads `.env.rehearsal` instead of `.env`. Laravel selects one dotenv file; it doesn't fill missing keys from `.env`. The managed launcher requires the dedicated file so it cannot silently fall back to the application's normal environment file.

Pass per-run settings or CI secrets as process environment overrides, for example `laravelServer({ env: { APP_KEY: process.env.E2E_APP_KEY } })`. Defined values in `env` take precedence over `.env.rehearsal`; unset values are omitted. Playwright's serialized web-server config contains only a path to a private temporary file, never the override values. The file stays available for relaunches and is removed when the process that loaded the config exits normally. The launcher does not automatically forward arbitrary parent variables, and it owns `APP_ENV`, `APP_URL`, and its isolation settings.

If your frontend is in another directory, run Composer and the Artisan installer in the Laravel root, and install the npm dependencies and browsers in the frontend project. Follow the [split frontend guide](./ssr) to point each client at the right origin.

To use another environment, run `php artisan rehearsal:install --environment=browser-tests` and set `laravelServer({ environment: "browser-tests" })`. The installer adds the new environment to the allowlist in its dotenv file. To reuse an existing `.env.playwright`, set `environment: "playwright"` and add `playwright` to `REHEARSAL_ENVIRONMENTS` or `rehearsal.environments`.

## Run your first test

**`playwright.config.ts`**

```ts
import { defineConfig } from "@playwright/test";
import { laravelServer } from "@sti3bas/rehearsal";

export default defineConfig({
  fullyParallel: true,
  workers: 4,
  use: { baseURL: "http://127.0.0.1:8100" },
  webServer: laravelServer(),
});
```

**`tests/app.spec.ts`**

```ts
import { expect, test } from "@sti3bas/rehearsal";

test("creates a user in this test's database", async ({ laravel, page }) => {
  const user = await laravel.factory("User", {
    name: "Ada",
    email: "ada@example.test",
  });

  expect(user.name).toBe("Ada");
  expect((await page.goto("/"))?.ok()).toBe(true);
});
```

This assumes the standard `App\Models\User` factory and a reachable root page. Run `npx playwright test`. To customize the package, publish `config/rehearsal.php`:

```sh
php artisan vendor:publish --tag=rehearsal-config
```

Use the [configuration guide](./configuration) to choose your database and environment, or [generate types](../concepts/factories#generate-types-from-your-laravel-app) once your first test runs.
