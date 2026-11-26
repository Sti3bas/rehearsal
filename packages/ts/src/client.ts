import type { APIRequestContext, BrowserContext, Page } from "@playwright/test";
import { capabilityHeader, loopback, markerCookie, markerHeader, protocol } from "./transport.js";

export interface FactoryDefinition {
  model: string;
  attrs?: Record<string, unknown>;
  states?: string[];
  count?: number;
}

type Row = Record<string, unknown>;
type FactoryResult = Row | Row[];

export class Laravel {
  private context?: BrowserContext;
  private pages = new Set<Page>();
  private time?: Date;
  readonly queue = {
    drain: (options: { queues?: string[]; maxJobs?: number } = {}) =>
      this.operation<{ code: number; removed: number; pending: number; failed: number }>("queue.drain", options),
  };

  constructor(
    private origin: string,
    private request: APIRequestContext,
    private capability: string,
    private marker: string,
    private frontendOrigin = origin,
  ) {}

  async bindContext(context: BrowserContext) {
    const origins = [new URL(this.origin), new URL(this.frontendOrigin)];
    if (!origins.every(loopback)) {
      throw new Error("Rehearsal attempt cookies require loopback origins.");
    }
    const hosts = new Map(origins.map((url) => [url.hostname, url.origin]));
    await context.addCookies([...hosts.values()].map((origin) => ({
      name: markerCookie, value: this.marker, url: origin + "/", httpOnly: true, sameSite: "Lax",
    })));
    this.context ??= context;
  }

  async preparePage(page: Page) {
    this.pages.add(page);
    page.once("close", () => this.pages.delete(page));
    if (this.time) await page.clock.setFixedTime(this.time);
  }

  async factory(model: string, attrs?: Row, options?: { states?: string[] }): Promise<Row>;
  async factory(model: string, attrs: Row, options: { states?: string[]; count: number }): Promise<Row[]>;
  async factory(items: FactoryDefinition[]): Promise<FactoryResult[]>;
  async factory(model: string | FactoryDefinition[], attrs: Row = {}, options: Omit<FactoryDefinition, "model" | "attrs"> = {}) {
    const batch = Array.isArray(model);
    const items = batch ? model : [{ model, attrs, ...options }];
    const rows = await this.operation<FactoryResult[]>("factory", { items });
    return batch ? rows : rows[0];
  }

  async login(user: { id: number | string }) {
    if (!this.context) throw new Error("Rehearsal login requires a browser context fixture.");
    return this.post<number | string>(this.context.request, "login", user);
  }

  async travel(value: string | Date) {
    const time = new Date(value);
    await this.operation("travel", { time: time.toISOString() });
    this.time = time;
    await Promise.all([...this.pages].map((page) => page.clock.setFixedTime(time)));
  }

  artisan(name: string, args: Record<string, unknown> = {}) {
    return this.operation<{ code: number }>("artisan", { name, args });
  }

  operation<T = unknown>(name: string, data: Record<string, unknown> = {}) {
    return this.post<T>(this.request, "call", { name, data });
  }

  private async post<T>(request: APIRequestContext, endpoint: string, data: object): Promise<T> {
    const response = await request.post(this.origin + "/_rehearsal/" + endpoint, {
      headers: {
        Accept: "application/json",
        [capabilityHeader]: this.capability,
        [markerHeader]: this.marker,
      },
      data,
      maxRedirects: 0,
    });
    if (!response.ok()) throw new Error("Rehearsal " + endpoint + " failed (HTTP " + response.status() + ").");
    return (await response.json()).data as T;
  }
}

export async function startAttempt(request: APIRequestContext, origin: string, capability: string, slot: number) {
  const response = await request.post(origin + "/_rehearsal/start", {
    headers: { Accept: "application/json", [capabilityHeader]: capability },
    data: { protocol, slot },
    maxRedirects: 0,
  });
  if (!response.ok()) throw new Error("Rehearsal could not reset slot " + slot + " (HTTP " + response.status() + ").");
  return (await response.json()) as { marker: string; resetMs: Record<string, number> };
}
