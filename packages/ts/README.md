# Rehearsal for Playwright

**Parallel Playwright testing for Laravel**

`@sti3bas/rehearsal` supplies `laravelServer()`, native Playwright fixtures for Laravel factories, login, and time travel, and optional Reverb, SSR, and reset timing helpers. It works with the `sti3bas/rehearsal` Composer package on Laravel 13.

The packages are not published yet. See the [project README](https://github.com/Sti3bas/rehearsal#readme) for requirements, the starter, and the complete quickstart.

On Nuxt 4, add `modules: ["@sti3bas/rehearsal/nuxt"]` to `nuxt.config.ts` and pass `$fetch: useRehearsalFetch()` to Laravel-facing `useFetch` calls. Enable forwarding only in the test server with `NUXT_REHEARSAL_ENABLED=true`. See the [Nuxt setup](https://github.com/Sti3bas/rehearsal#nuxt-4) for the defaults and overrides.

With forwarding enabled, Node Nitro routes can use ordinary `$fetch` or `ofetch` to the configured Laravel origin. For Typesense InstantSearch, opt in with `NUXT_REHEARSAL_TYPESENSE=true` and use `useTypesenseInstantSearch()` to configure your adapter and index names. Use `laravelServer({ server: "frankenphp" })` to run the managed stack on an installed FrankenPHP executable.

[MIT License](https://github.com/Sti3bas/rehearsal/blob/main/LICENSE)
