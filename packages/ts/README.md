# Rehearsal for Playwright

**Parallel Playwright testing for Laravel**

`@sti3bas/rehearsal` supplies `laravelServer()`, native Playwright fixtures for Laravel factories, login, and time travel, and optional Reverb, SSR, and reset timing helpers. It works with the `sti3bas/rehearsal` Composer package on Laravel 13.

Install from [npm](https://www.npmjs.com/package/@sti3bas/rehearsal):

```sh
npm install -D @sti3bas/rehearsal @playwright/test
```

See the [project README](https://github.com/Sti3bas/rehearsal#readme) for the complete quickstart, including the [Packagist package](https://packagist.org/packages/sti3bas/rehearsal).

On Nuxt 4, add `modules: ["@sti3bas/rehearsal/nuxt"]` to `nuxt.config.ts` and pass `$fetch: useRehearsalFetch()` to Laravel-facing `useFetch` calls. Enable forwarding only in the test server with `NUXT_REHEARSAL_ENABLED=true`. See the [Nuxt setup](https://github.com/Sti3bas/rehearsal#nuxt-4) for the defaults and overrides.

With forwarding enabled, Node Nitro 2 routes can use ordinary `$fetch` or `ofetch` to the configured Laravel origin. Automatic forwarding is not available on Nitro 3. For Typesense InstantSearch, opt in with `NUXT_REHEARSAL_TYPESENSE=true` and use `useTypesenseInstantSearch()` to configure your adapter and index names. Use `laravelServer({ server: "frankenphp" })` to run the managed stack on an installed FrankenPHP executable.

[MIT License](https://github.com/Sti3bas/rehearsal/blob/main/LICENSE)
