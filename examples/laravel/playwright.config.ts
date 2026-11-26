import { defineConfig } from "@playwright/test";
import { laravelServer } from "@sti3bas/rehearsal";
import { fileURLToPath } from "node:url";

const engine = process.env.REHEARSAL_E2E_ENGINE ?? "sqlite";
const port = Number(process.env.REHEARSAL_E2E_PORT ?? 8100);
const scout = process.env.REHEARSAL_E2E_SCOUT;
const serviceHost = process.env.REHEARSAL_E2E_SERVICE_HOST;
const reverb = process.env.REHEARSAL_E2E_REVERB === "true";
const ssrPort = process.env.REHEARSAL_E2E_NUXT_PORT;
const laravel = laravelServer({
  port,
  integrations: {
    ...(scout ? { REHEARSAL_INTEGRATION_SCOUT: scout } : {}),
    ...(serviceHost ? { REHEARSAL_INTEGRATION_SERVICE_HOST: serviceHost } : {}),
    ...(reverb ? { REHEARSAL_INTEGRATION_REVERB: "true" } : {}),
  },
  database: engine === "sqlite" ? {} : {
    DB_CONNECTION: engine,
    DB_HOST: "127.0.0.1",
    DB_PORT: process.env.REHEARSAL_E2E_DB_PORT ?? (engine === "mysql" ? "13386" : "15438"),
    DB_USERNAME: engine === "mysql" ? "root" : "postgres",
    DB_PASSWORD: "rehearsal_local",
    REHEARSAL_DB_DISPOSABLE: "true",
  },
});

export default defineConfig({
  testDir: "./tests",
  outputDir: "./test-results/" + engine + "-" + port,
  testIgnore: [
    ...(scout ? [] : ["**/services.spec.ts"]),
    ...(reverb ? [] : ["**/reverb.spec.ts"]),
    ...(ssrPort ? [] : ["**/ssr.spec.ts"]),
  ],
  fullyParallel: true,
  workers: Number(process.env.REHEARSAL_E2E_WORKERS ?? 4),
  use: { baseURL: "http://127.0.0.1:" + port, browserName: "chromium" },
  reporter: [["line"], ["@sti3bas/rehearsal/reporter"]],
  webServer: ssrPort ? [
    laravel,
    {
      command: "npm run dev -- --host 127.0.0.1 --port " + Number(ssrPort),
      cwd: fileURLToPath(new URL("../nuxt/", import.meta.url)),
      url: "http://127.0.0.1:" + Number(ssrPort) + "/api/ready",
      timeout: 120_000,
      env: { NUXT_REHEARSAL_ENABLED: "true", NUXT_REHEARSAL_LARAVEL_ORIGIN: "http://127.0.0.1:" + port },
    },
  ] : laravel,
});
