# Rehearsal example benchmark

From the repository root, install the example's Composer dependencies and workspace npm dependencies, run `npm run build`, and install Chromium with `npx playwright install chromium --only-shell`. Run `php artisan rehearsal:install` once in `examples/laravel`.

```sh
node bench/run.mjs
REHEARSAL_BENCH_SCOUT=typesense node bench/run.mjs --http-only
REHEARSAL_BENCH_REVERB=true node bench/run.mjs --http-only
```

The first command runs the example on disposable SQLite. The others use the optional services in `examples/laravel/compose.integrations.yml`; `REHEARSAL_BENCH_SCOUT` also accepts `database` and `meilisearch`. Set `REHEARSAL_BENCH_PORT` to an unused loopback port (default 8195). Set `REHEARSAL_BENCH_LOG` to a local file for subprocess output. The default sample sizes are 500 requests and 30 warm resets, configurable with `REHEARSAL_BENCH_REQUESTS` and `REHEARSAL_BENCH_ATTEMPTS`.

- **Request:** Sequential HTTP timings for the same 204 API route, without and with attempt middleware and integrations. Each server gets 30 warm-up requests. Measurements include HTTP and Laravel boot, not only the middleware.
- **Startup:** Launch to first ready response. The managed measurement includes provisioning. `provisionMs` separately times a fresh `rehearsal:provision` CLI invocation, including PHP startup and Laravel boot.
- **Reset:** First and warm per-integration timings from the protocol, plus warm end-to-end request timings. Per-integration timings exclude client metadata extraction.
- **Suite:** End-to-end wall time for the existing SQLite suite, with one and four workers in separate runs, including server startup and cleanup. Omit this with `--http-only`.

Compare revisions on the same idle machine, using the same PHP/Node builds and service versions. Repeat runs before drawing conclusions from small wall-clock differences. To attribute filesystem work on Linux, use a separate, untimed syscall trace:

```sh
REHEARSAL_BENCH_REQUESTS=60 REHEARSAL_BENCH_ATTEMPTS=5 \
  strace -f -qq -e trace=openat,newfstatat,flock -o rehearsal-syscalls.log \
  node bench/run.mjs --http-only
```
