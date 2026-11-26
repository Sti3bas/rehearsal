import { spawn, type ChildProcess } from "node:child_process";
import { randomBytes } from "node:crypto";
import { once } from "node:events";
import { access, mkdir, rm, writeFile } from "node:fs/promises";
import { join } from "node:path";
import { privateLaunchPath } from "./transport.js";

const root = process.cwd();
const port = Number(process.argv[2]);
const workers = Number(process.argv[3]);
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
Object.assign(env, {
  APP_ENV: "playwright",
  APP_URL: "http://127.0.0.1:" + port,
  APP_DEBUG: "false",
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

async function artisan(args: string[]) {
  child = spawn("php", ["artisan", ...args, "--env=playwright", "--no-interaction"], {
    cwd: root, env, stdio: "inherit", detached: true,
  });
  const [code] = await once(child, "exit");
  child = undefined;
  return code ?? 1;
}

await access(join(root, ".env.playwright")).catch(() => {
  throw new Error("Run php artisan rehearsal:install before launching Rehearsal.");
});
await mkdir(join(root, ".rehearsal"), { mode: 0o700, recursive: true });
await writeFile(launchPath, JSON.stringify({ run, capability }), { mode: 0o600, flag: "wx" });
let cleanup = true;
try {
  if (await artisan(["rehearsal:provision"])) throw new Error("Rehearsal provisioning failed.");
  if (!stopping) {
    const code = await artisan(["serve", "--host=127.0.0.1", "--port=" + port, "--no-reload", "--tries=1"]);
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
