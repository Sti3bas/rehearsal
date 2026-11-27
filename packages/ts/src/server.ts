import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

interface ServerOptions {
  port?: number;
  host?: "127.0.0.1" | "localhost";
  cwd?: string;
  server?: "artisan" | "frankenphp" | "auto";
  phpWorkers?: number;
  environment?: string;
  env?: Record<string, string | undefined>;
  database?: Record<string, string>;
  integrations?: Record<`REHEARSAL_INTEGRATION_${string}`, string>;
}

function quote(value: string) {
  return "'" + value.replaceAll("'", "'\\''") + "'";
}

const pendingOverrides = new Set<string>();

function removeUnconsumedOverrides() {
  for (const directory of pendingOverrides) {
    try { rmSync(directory, { recursive: true, force: true }); } catch { /* best effort at exit */ }
  }
}

function writeOverrides(overrides: string) {
  const directory = mkdtempSync(join(tmpdir(), "rehearsal-env-"));
  const path = join(directory, "overrides.json");
  try {
    writeFileSync(path, overrides, { mode: 0o600, flag: "wx" });
  } catch (error) {
    rmSync(directory, { recursive: true, force: true });
    throw error;
  }
  if (pendingOverrides.size === 0) process.once("exit", removeUnconsumedOverrides);
  pendingOverrides.add(directory);
  return path;
}

export function laravelServer({ port = 8100, host = "127.0.0.1", cwd = process.cwd(), server = "artisan", phpWorkers = 8, environment = "rehearsal", env = {}, database = {}, integrations = {} }: ServerOptions = {}) {
  if (!Number.isInteger(port) || port < 1024 || port > 65535 || !Number.isInteger(phpWorkers) || phpWorkers < 2 || phpWorkers > 64) {
    throw new Error("Invalid Rehearsal port or PHP worker count.");
  }
  if (host !== "127.0.0.1" && host !== "localhost") throw new Error("Invalid Rehearsal loopback host.");
  if (!/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/.test(environment)) throw new Error("Invalid Rehearsal environment name.");
  const script = fileURLToPath(new URL("./launcher.js", import.meta.url));
  const managed = [...Object.entries(database), ...Object.entries(integrations)];
  const application = Object.entries(env).filter(([, value]) => value !== undefined);
  const path = managed.length || application.length ? writeOverrides(JSON.stringify({
    managed: Object.fromEntries(managed.map(([name, value]) => ["REHEARSAL_MANAGED_" + name, value])),
    env: Object.fromEntries(application),
  })) : undefined;
  return {
    command: [process.execPath, script, String(port), String(phpWorkers), server, host].map(quote).join(" "),
    cwd: resolve(cwd),
    env: {
      REHEARSAL_MANAGED_APP_ENV: environment,
      ...(path ? { REHEARSAL_MANAGED_ENV_FILE: path } : {}),
    },
    url: "http://" + host + ":" + port + "/_rehearsal/ready",
    timeout: 120_000,
    reuseExistingServer: false,
    stderr: "pipe" as const,
    gracefulShutdown: { signal: "SIGTERM" as const, timeout: 10_000 },
  };
}
