import { expect } from "@playwright/test";
import type { BrowserContext } from "@playwright/test";
import type { Laravel } from "./client.js";
import { loopback } from "./transport.js";

export class Reverb {
  constructor(private laravel: Laravel) {}

  subscribed(channel: string) {
    return this.laravel.operation<boolean>("reverb.subscribed", { channel });
  }

  async waitForSubscriber(channel: string, options: { timeout?: number } = {}) {
    await expect.poll(() => this.subscribed(channel), {
      timeout: options.timeout ?? 10_000,
      message: "waiting for a subscriber on " + channel,
    }).toBe(true);
  }

  broadcast(channel: string, event: string, payload: Record<string, unknown> = {}) {
    return this.laravel.operation<null>("reverb.broadcast", { channel, event, payloadJson: JSON.stringify(payload) });
  }
}

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
