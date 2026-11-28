---
description: Install the Laravel and Playwright packages, configure the managed server, and run your first test.
---

# Installation

Install both packages as development dependencies: one in the Laravel app, one in the project that runs Playwright. They can live in the same directory or in separate projects.

| Requirement | Supported version |
| --- | --- |
| Laravel | 13 |
| PHP | 8.3+ with the PDO extension for your database |
| Node.js | 22.18+ in the 22 line, or 24 |
| Playwright Test | 1.65.1 or compatible |

Use a classic Laravel request lifecycle, not Octane. The default Artisan server uses PHP CLI workers on Linux or macOS.

## 1. Install in Laravel

<div class="install-stack"><img src="/brands/laravel.svg" alt="" height="24" width="24" /><span>Laravel</span><img src="/brands/composer.svg" alt="" height="24" width="24" /><span>Composer</span></div>

Run these from the Laravel root, where `artisan` lives:

```sh
composer require --dev sti3bas/rehearsal
php artisan rehearsal:install
```

The installer creates `.env.rehearsal` with its own app key and SQLite defaults; it does not overwrite an existing file. No changes to `bootstrap/app.php` are needed. Keep your database migrations for sessions, cache, jobs, and failed jobs if you use those features.

::: warning Use a dedicated test environment
Keep `.env.rehearsal` and `.rehearsal/` out of version control. Laravel reads one dotenv file and does not fill missing values from `.env`. The launcher requires the dedicated file and sets `APP_ENV=rehearsal` for its managed processes.
:::

## 2. Install in the Playwright project

<div class="install-stack"><img src="/brands/playwright.svg" alt="" height="24" width="24" /><span>Playwright Test</span></div>

Run these from the project that will contain `playwright.config.ts`. Pick your package manager:

::: code-group

```sh [npm]
npm install -D @sti3bas/rehearsal @playwright/test
npx playwright install chromium
```

```sh [pnpm]
pnpm add -D @sti3bas/rehearsal @playwright/test
pnpm exec playwright install chromium
```

```sh [Yarn]
yarn add -D @sti3bas/rehearsal @playwright/test
yarn playwright install chromium
```

:::

## 3. Run your first test {#run-your-first-test}

For a Laravel-rendered app, configure Playwright to use Laravel for both the browser's base URL and the managed server. For Nuxt or another separate frontend, follow the [frontend setup guide](../frontends/) for the two origins instead.

```ts{2,8} [playwright.config.ts]
import { defineConfig } from "@playwright/test";
import { laravelServer } from "@sti3bas/rehearsal";

export default defineConfig({
  fullyParallel: true,
  workers: 4,
  use: { baseURL: "http://127.0.0.1:8100" },
  webServer: laravelServer(),
});
```

Import `test` from Rehearsal to get the `laravel` fixture:

```ts{1,4-7} [tests/app.spec.ts]
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

This assumes a standard `App\Models\User` factory and a root page that returns a successful response.

::: code-group

```sh [npm]
npx playwright test
```

```sh [pnpm]
pnpm exec playwright test
```

```sh [Yarn]
yarn playwright test
```

:::

## 4. Customize when needed

```sh
php artisan vendor:publish --tag=rehearsal-config
```

Use the [configuration guide](./configuration) to adjust the defaults, or [generate types](../concepts/factories#generate-types-from-your-laravel-app) once your first test runs.

::: details Use a different dotenv file or pass CI secrets
To use a custom environment, run `php artisan rehearsal:install --environment=browser-tests` and set `laravelServer({ environment: "browser-tests" })`. The installer adds the environment to the allowlist in the new file. For an existing `.env.playwright`, use `environment: "playwright"` and add `playwright` to `REHEARSAL_ENVIRONMENTS` or `rehearsal.environments`.

Pass explicit process overrides with `laravelServer({ env: { APP_KEY: process.env.E2E_APP_KEY } })`. Defined values take precedence over dotenv; unset values are omitted. The serialized Playwright config contains only the path to a private temporary file, which is kept until the loading process exits normally. The launcher does not forward arbitrary parent variables and owns `APP_ENV`, `APP_URL`, and isolation settings.
:::
