import { defineConfig } from "@playwright/test";
import { laravelServer } from "@sti3bas/rehearsal";

const engine = process.env.REHEARSAL_E2E_ENGINE ?? "sqlite";
const port = Number(process.env.REHEARSAL_E2E_PORT ?? 8100);

export default defineConfig({
  testDir: "./tests",
  outputDir: "./test-results/" + engine + "-" + port,
  fullyParallel: true,
  workers: Number(process.env.REHEARSAL_E2E_WORKERS ?? 4),
  use: { baseURL: "http://127.0.0.1:" + port, browserName: "chromium" },
  webServer: laravelServer({
    port,
    database: engine === "sqlite" ? {} : {
      DB_CONNECTION: engine,
      DB_HOST: "127.0.0.1",
      DB_PORT: process.env.REHEARSAL_E2E_DB_PORT ?? (engine === "mysql" ? "13386" : "15438"),
      DB_USERNAME: engine === "mysql" ? "root" : "postgres",
      DB_PASSWORD: "rehearsal_local",
      REHEARSAL_DB_DISPOSABLE: "true",
    },
  }),
});
