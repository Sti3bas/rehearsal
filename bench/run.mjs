import { spawn } from "node:child_process";
import { once } from "node:events";
import { closeSync, openSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { setTimeout as delay } from "node:timers/promises";
import { capabilityHeader, managedLaunch, markerHeader, protocol } from "../packages/ts/dist/transport.js";

const root = fileURLToPath(new URL("../", import.meta.url));
const cwd = fileURLToPath(new URL("../examples/laravel/", import.meta.url));
const launcher = fileURLToPath(new URL("../packages/ts/dist/launcher.js", import.meta.url));
const port = Number(process.env.REHEARSAL_BENCH_PORT ?? 8195);
const origin = "http://127.0.0.1:" + port;
const requests = Number(process.env.REHEARSAL_BENCH_REQUESTS ?? 500);
const attempts = Number(process.env.REHEARSAL_BENCH_ATTEMPTS ?? 30);
const scout = process.env.REHEARSAL_BENCH_SCOUT;
const logfile = openSync(process.env.REHEARSAL_BENCH_LOG ?? "/dev/null", "a");

if (![port, requests, attempts].every((value) => Number.isSafeInteger(value) && value > 0)
    || port < 1024 || port > 65535) throw new Error("Invalid benchmark port or sample count.");

function stats(samples) {
  const sorted = [...samples].sort((a, b) => a - b);
  const quantile = (p) => Number(sorted[Math.ceil(p * sorted.length) - 1].toFixed(3));
  return { medianMs: quantile(0.5), p95Ms: quantile(0.95) };
}

async function fetchOk(path, options) {
  const response = await fetch(origin + path, { signal: AbortSignal.timeout(10_000), ...options });
  if (!response.ok) throw new Error(path + " returned HTTP " + response.status);
  return response;
}

async function measureRequests(headers) {
  const samples = [];
  for (let i = -30; i < requests; i++) {
    const start = performance.now();
    await fetchOk("/api/noop", { headers });
    if (i >= 0) samples.push(performance.now() - start);
  }
  return stats(samples);
}

async function withServer(command, args, env, path, run) {
  const start = performance.now();
  const child = spawn(command, args, { cwd, env, detached: true, stdio: ["ignore", logfile, logfile] });
  const exit = once(child, "exit");
  try {
    for (;;) {
      if (child.exitCode !== null || performance.now() - start > 120_000) {
        throw new Error("Benchmark server did not start. Set REHEARSAL_BENCH_LOG to capture its output.");
      }
      if (await fetchOk(path).then(() => true, () => false)) break;
      await delay(20);
    }
    return await run(performance.now() - start);
  } finally {
    if (child.pid && child.exitCode === null) process.kill(-child.pid, "SIGTERM");
    await exit;
  }
}

async function suite(workers) {
  const start = performance.now();
  const child = spawn("npm", ["run", "e2e"], {
    cwd: root,
    env: { ...process.env, REHEARSAL_E2E_WORKERS: String(workers), REHEARSAL_E2E_ENGINE: "sqlite", REHEARSAL_E2E_PORT: String(port) },
    stdio: ["ignore", logfile, logfile],
  });
  const [code] = await once(child, "exit");
  if (code !== 0) throw new Error("SQLite example suite failed with " + workers + " workers.");
  return Number((performance.now() - start).toFixed(1));
}

try {
  const args = ["artisan", "serve", "--env=playwright", "--host=127.0.0.1", "--port=" + port, "--no-reload", "--tries=1"];
  const disabled = await withServer("php", args, { ...process.env, REHEARSAL_ENABLED: "false", PHP_CLI_SERVER_WORKERS: "8" },
    "/api/noop", async (startupMs) => ({ startupMs: Math.round(startupMs), request: await measureRequests() }));
  const enabled = await withServer(process.execPath, [launcher, String(port), "8", "artisan"], {
    ...process.env,
    ...(scout ? { REHEARSAL_MANAGED_REHEARSAL_INTEGRATION_SCOUT: scout } : {}),
  }, "/_rehearsal/ready", async (startupMs) => {
    const launch = await managedLaunch(cwd, port);
    const startAttempt = async () => {
      const start = performance.now();
      const response = await fetchOk("/_rehearsal/start", {
        method: "POST",
        headers: { "Content-Type": "application/json", [capabilityHeader]: launch.capability },
        body: JSON.stringify({ protocol, slot: 1 }),
      });
      return { ...await response.json(), elapsed: performance.now() - start };
    };
    const cold = await startAttempt();
    const warm = Array.from({ length: attempts });
    for (let i = 0; i < warm.length; i++) warm[i] = await startAttempt();
    const reset = Object.fromEntries(Object.keys(cold.resetMs).map((key) =>
      [key, { coldMs: cold.resetMs[key], ...stats(warm.map((sample) => sample.resetMs[key])) }]));
    return {
      startupMs: Math.round(startupMs), reset, resetTotal: stats(warm.map((sample) => sample.elapsed)),
      request: await measureRequests({ [markerHeader]: warm.at(-1).marker }),
    };
  });
  const suiteMs = process.argv.includes("--http-only") ? undefined : { oneWorker: await suite(1), fourWorkers: await suite(4) };
  console.log(JSON.stringify({ requests, attempts, scout: scout ?? null, disabled, enabled, suiteMs }, null, 2));
} finally {
  closeSync(logfile);
}
