import { spawn, type ChildProcess } from "node:child_process";
import { randomBytes } from "node:crypto";
import { once } from "node:events";
import { constants } from "node:fs";
import { access, mkdir, rm, writeFile } from "node:fs/promises";
import { delimiter, join, resolve } from "node:path";
import { privateLaunchPath } from "./transport.js";

const root = process.cwd();
const port = Number(process.argv[2]);
const workers = Number(process.argv[3]);
const host = process.argv[5] ?? "127.0.0.1";
const environment = process.env.REHEARSAL_MANAGED_APP_ENV ?? "playwright";
if (!/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/.test(environment)) throw new Error("Invalid Rehearsal environment name.");
const run = randomBytes(12).toString("hex");
const capability = randomBytes(32).toString("hex");
const launchPath = privateLaunchPath(root, port);
const runPath = join(root, ".rehearsal", "runs", run);

const env: NodeJS.ProcessEnv = {};
for (const key of ["PATH", "HOME", "LANG", "LC_ALL", "TMPDIR", "TZ", "PHP_INI_SCAN_DIR", "LD_LIBRARY_PATH"]) {
  if (process.env[key] !== undefined) env[key] = process.env[key];
}
for (const key of ["DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_USERNAME", "DB_PASSWORD", "REHEARSAL_DB_DISPOSABLE"]) {
  const value = process.env["REHEARSAL_MANAGED_" + key];
  if (value !== undefined) env[key] = value;
}
for (const [key, value] of Object.entries(process.env)) {
  if (/^REHEARSAL_MANAGED_REHEARSAL_INTEGRATION_[A-Z0-9_]+$/.test(key)) {
    env[key.slice("REHEARSAL_MANAGED_".length)] = value;
  }
}
Object.assign(env, {
  APP_ENV: environment,
  APP_URL: "http://" + host + ":" + port,
  APP_CONFIG_CACHE: join(runPath, "config.php"),
  APP_ROUTES_CACHE: join(runPath, "routes.php"),
  APP_EVENTS_CACHE: join(runPath, "events.php"),
  PHP_CLI_SERVER_WORKERS: String(workers),
  REHEARSAL_ENABLED: "true",
  REHEARSAL_RUN: run,
  REHEARSAL_CAPABILITY: capability,
});

let child: ChildProcess | undefined;
let stopping = false;
function stop() {
  stopping = true;
  if (child?.pid) {
    try { process.kill(-child.pid, "SIGTERM"); } catch { /* already stopped */ }
  }
}
process.on("SIGTERM", stop);
process.on("SIGINT", stop);

async function execute(command: string, args: string[]) {
  child = spawn(command, args, {
    cwd: root, env, stdio: "inherit", detached: true,
  });
  try {
    const [code] = await once(child, "exit");
    return code ?? 1;
  } finally {
    child = undefined;
  }
}

function artisan(args: string[]) {
  return execute("php", ["artisan", ...args, "--env=" + environment, "--no-interaction"]);
}

const server = process.argv[4] ?? "artisan";
let frankenphp: string | undefined;
if (server !== "artisan") {
  if (server !== "frankenphp" && server !== "auto") throw new Error("Invalid Rehearsal server.");
  for (const directory of (env.PATH ?? "").split(delimiter)) {
    const candidate = resolve(directory, "frankenphp");
    if (await access(candidate, constants.X_OK).then(() => true, () => false)) {
      frankenphp = candidate;
      break;
    }
  }
  if (!frankenphp && server === "frankenphp") throw new Error("FrankenPHP was not found on PATH.");
}

await access(join(root, ".env." + environment)).catch(() => {
  throw new Error("Run php artisan rehearsal:install before launching Rehearsal.");
});
await mkdir(join(root, ".rehearsal"), { mode: 0o700, recursive: true });
await writeFile(launchPath, JSON.stringify({ run, capability }), { mode: 0o600, flag: "wx" });
let cleanup = true;
try {
  if (await artisan(["rehearsal:provision"])) throw new Error("Rehearsal provisioning failed.");
  if (!stopping) {
    const code = frankenphp
      ? await execute(frankenphp, ["php-server", "--listen=127.0.0.1:" + port, "--root=public"])
      : await artisan(["serve", "--host=127.0.0.1", "--port=" + port, "--no-reload", "--tries=1"]);
    if (!stopping && code) throw new Error("Rehearsal's Laravel server exited.");
  }
} finally {
  process.removeListener("SIGTERM", stop);
  process.removeListener("SIGINT", stop);
  try {
    if (await artisan(["rehearsal:cleanup"])) cleanup = false;
  } finally {
    await rm(launchPath, { force: true });
    if (cleanup) await rm(runPath, { recursive: true, force: true });
    else {
      console.error("Rehearsal cleanup failed; ownership records were retained for operator recovery.");
      process.exitCode = 1;
    }
  }
}
