<div align="center">
  <img src="assets/banner.png" width="100%" alt="Rehearsal: four Playwright workers passing tests under theatre spotlights">
  <h1><img src="assets/logo.svg" width="320" alt="Rehearsal"></h1>
  <p><strong>Parallel Playwright testing for Laravel</strong></p>
  <p>
    <a href="https://github.com/Sti3bas/rehearsal/actions/workflows/ci.yml"><img src="https://github.com/Sti3bas/rehearsal/actions/workflows/ci.yml/badge.svg?branch=main" alt="CI"></a>
    <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green.svg" alt="License: MIT"></a>
    <img src="https://img.shields.io/badge/PHP-8.3%2B-777bb4" alt="PHP 8.3+">
    <img src="https://img.shields.io/badge/Laravel-13-ff4433" alt="Laravel 13">
  </p>
</div>

Rehearsal connects a real Laravel application to Playwright Test. Run browser tests in parallel against one Laravel server while Rehearsal resets the database, files, sessions, cache, and queues for each attempt. Use Laravel factories and named operations from TypeScript while keeping Playwright's browser APIs, traces, and retries.

**[Read the documentation](https://sti3bas.github.io/rehearsal/)**

[Getting started](https://sti3bas.github.io/rehearsal/guide/getting-started.html) · [Configuration](https://sti3bas.github.io/rehearsal/guide/configuration.html) · [Concepts](https://sti3bas.github.io/rehearsal/concepts/isolation.html) · [Extending](https://sti3bas.github.io/rehearsal/guide/extending.html) · [API reference](https://sti3bas.github.io/rehearsal/reference/typescript.html)

## Requirements

Laravel 13, PHP 8.3+ with the PDO extension for your database, Node.js 22.18+ on the 22.x line or Node.js 24, and Playwright Test 1.65.1 or compatible. The default managed Artisan server uses PHP CLI workers on Linux or macOS. Use a classic Laravel request lifecycle, not Octane.

## Quick start

From a Laravel project with Playwright in the same directory:

```sh
composer require --dev sti3bas/rehearsal
npm install -D @sti3bas/rehearsal @playwright/test
php artisan rehearsal:install
npx playwright install chromium
```

In `playwright.config.ts`:

```ts
import { defineConfig } from "@playwright/test";
import { laravelServer } from "@sti3bas/rehearsal";

export default defineConfig({
  use: { baseURL: "http://127.0.0.1:8100" },
  webServer: laravelServer(),
});
```

Import `test` from `@sti3bas/rehearsal` in your specs to use the `laravel` fixture. Run `npx playwright test`. The [getting started guide](https://sti3bas.github.io/rehearsal/guide/getting-started.html) includes a complete first test and setup for a separate frontend.

## License

[Composer package](packages/php/README.md) · [npm package](packages/ts/README.md) · [MIT License](LICENSE) · [Logo](assets/logo.svg) · [Mark](assets/mark.svg)
