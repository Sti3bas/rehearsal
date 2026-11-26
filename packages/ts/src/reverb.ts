import type { BrowserContext } from "@playwright/test";
import { loopback } from "./transport.js";

export interface ReverbClientConfig {
  key: string;
  host: string;
  port: number;
  scheme: "ws";
}

export async function injectReverb(context: BrowserContext, baseURL: string, metadata: unknown) {
  const app = new URL(baseURL);
  const data = metadata as Partial<ReverbClientConfig> | undefined;
  if (!loopback(app) || !data || !data.key || !/^[a-f0-9]{32}$/.test(data.key)
    || !["127.0.0.1", "[::1]"].includes(data.host ?? "") || data.scheme !== "ws"
    || !Number.isInteger(data.port) || data.port! < 1 || data.port! > 65_535) {
    throw new Error("Rehearsal did not provide valid Reverb client config.");
  }
  const { key, host, port, scheme } = data;
  await context.addInitScript(({ origin, config }) => {
    if (location.origin === origin) {
      Object.defineProperty(globalThis, "__REHEARSAL_REVERB__", { value: Object.freeze(config) });
    }
  }, { origin: app.origin, config: { key, host, port, scheme } });
}
