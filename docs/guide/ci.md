# CI and performance

Install Composer and npm dependencies first. Run the installer from the Laravel root, and the browser install and test commands from your Playwright project.

After installing app dependencies in CI, run:

```yaml
- run: php artisan rehearsal:install
- run: npx playwright install --with-deps chromium
- run: npx playwright test --workers=4
```

The [full workflow](https://github.com/Sti3bas/rehearsal/blob/main/.github/workflows/ci.yml) exercises all three DB engines at one and four workers, plus all opt-in services. To see reset costs, add the reporter next to your regular reporter:

```ts
reporter: [["line"], ["@sti3bas/rehearsal/reporter"]]
```

It reports mean/max reset times and the number of measured attempts, including retries. Start with a small worker count and measure on your runner. SQLite file copies are a simple starting point; if a suite needs MySQL or PostgreSQL behavior, test against that engine rather than swapping SQL semantics for speed. When concurrent requests in one SQLite attempt run transactions that read before writing, set `rehearsal.database.sqlite_transaction_mode` to `IMMEDIATE` on PHP 8.4+ to let writers wait at transaction start instead of failing to upgrade a read snapshot. The default is `DEFERRED`; `EXCLUSIVE` is also accepted.

## Run from source

```sh
composer install --working-dir=examples/laravel
(cd examples/laravel && php artisan rehearsal:install)
npm ci
npm run build
npx playwright install chromium --only-shell
npm run e2e
```

Add `REHEARSAL_E2E_WORKERS=1` for a serial run. The optional services can be exercised locally:

```sh
export REHEARSAL_UID="$(id -u)" REHEARSAL_GID="$(id -g)"
docker compose -f examples/laravel/compose.integrations.yml up --detach --build
for engine in database meilisearch typesense; do
  REHEARSAL_E2E_SCOUT="$engine" REHEARSAL_E2E_WORKERS=4 npm run e2e -- services.spec.ts
done
REHEARSAL_E2E_REVERB=true REHEARSAL_E2E_WORKERS=4 npm run e2e -- reverb.spec.ts
REHEARSAL_E2E_NUXT_PORT=8325 REHEARSAL_E2E_WORKERS=4 npm run e2e -- ssr.spec.ts
docker compose -f examples/laravel/compose.integrations.yml down
```

The example Typesense container binds to loopback and allows browser requests only from `http://127.0.0.1:8100` by default. If you use a different frontend origin, set `REHEARSAL_TYPESENSE_CORS_ORIGINS` to the expected origin (or comma-separated origins) when starting Compose. The example's public admin key is for this disposable container only; never reuse it or expose the service outside loopback.

## Working on these docs

From the repository root, use the supported Node version and run `npm ci`. Start a local server with `npm run docs:dev`, or run `npm run docs:build` and `npm run docs:preview` to verify the production build. The Pages workflow publishes that build on pushes to `main`; set the repository's **Settings → Pages → Source** to **GitHub Actions** once to enable deployment.
