import { resolve } from "node:path";
import { fileURLToPath } from "node:url";

interface ServerOptions {
  port?: number;
  cwd?: string;
  phpWorkers?: number;
  database?: Record<string, string>;
  integrations?: Record<`REHEARSAL_INTEGRATION_${string}`, string>;
}

function quote(value: string) {
  return "'" + value.replaceAll("'", "'\\''") + "'";
}

export function laravelServer({ port = 8100, cwd = process.cwd(), phpWorkers = 8, database = {}, integrations = {} }: ServerOptions = {}) {
  if (!Number.isInteger(port) || port < 1024 || port > 65535 || !Number.isInteger(phpWorkers) || phpWorkers < 2 || phpWorkers > 64) {
    throw new Error("Invalid Rehearsal port or PHP worker count.");
  }
  const script = fileURLToPath(new URL("./launcher.js", import.meta.url));
  return {
    command: [process.execPath, script, String(port), String(phpWorkers)].map(quote).join(" "),
    cwd: resolve(cwd),
    env: Object.fromEntries([...Object.entries(database), ...Object.entries(integrations)]
      .map(([name, value]) => ["REHEARSAL_MANAGED_" + name, value])),
    url: "http://127.0.0.1:" + port + "/_rehearsal/ready",
    timeout: 120_000,
    reuseExistingServer: false,
    stderr: "pipe" as const,
    gracefulShutdown: { signal: "SIGTERM" as const, timeout: 10_000 },
  };
}
