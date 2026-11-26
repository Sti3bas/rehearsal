import type { APIRequestContext, BrowserContext, Page } from "@playwright/test";
import { encodeHttpRules, type HttpFakeRules } from "./http.js";
import { Mailbox } from "./mail.js";
import { Queue, type DrainOptions, type DrainResult } from "./queue.js";
import { capabilityHeader, loopback, markerCookie, markerHeader, protocol } from "./transport.js";
import { claimRoute, encodeRouteResponse, matchesRoute, mockResponse, routePattern, type RouteResponse, type RouteTarget } from "./routes.js";

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
  private contexts = new Set<BrowserContext>();
  private routeContexts = new WeakSet<BrowserContext>();
  private patterns: ReturnType<typeof routePattern>[] = [];
  private pages = new Set<Page>();
  private time?: Date;
  readonly queue = new Queue(this);
  readonly http = {
    fake: (rules: HttpFakeRules, options: { preventStrayRequests?: boolean } = {}) =>
      this.operation<null>("http.fake", { rules: encodeHttpRules(rules), ...options }),
  };
  readonly schedule = {
    run: async (names: string | string[], options: { drain?: DrainOptions } = {}) => {
      const result = await this.operation<{ results: { name: string; code: number }[]; drain: DrainResult | null }>(
        "schedule.run", { names: typeof names === "string" ? [names] : names, ...options },
      );
      const failure = result.results.find(({ code }) => code !== 0);
      if (failure) throw new Error(`Rehearsal scheduled task "${failure.name}" failed (exit ${failure.code}).`);
      return result;
    },
  };

  constructor(
    private origin: string,
    private request: APIRequestContext,
    private capability: string,
    private marker: string,
    private frontendOrigin = origin,
    private metadata: Record<string, unknown> = {},
  ) {}

  integrationMetadata(name: string): unknown {
    return Object.hasOwn(this.metadata, name) ? this.metadata[name] : undefined;
  }

  mailbox(recipient: string) {
    return new Mailbox(this, recipient);
  }

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
    this.contexts.add(context);
    context.once("close", () => this.contexts.delete(context));
    if (this.patterns.length) await this.installRouteMocks(context);
  }

  route(target: RouteTarget): ReturnType<Laravel["routeActions"]>;
  route<P>(target: (params: P) => RouteTarget, params: P): ReturnType<Laravel["routeActions"]>;
  route(target: () => RouteTarget): ReturnType<Laravel["routeActions"]>;
  route<P>(target: RouteTarget | ((params?: P) => RouteTarget), params?: P) {
    return this.routeActions(routePattern(typeof target === "function" ? target(params) : target, this.origin));
  }

  private routeActions(pattern: ReturnType<typeof routePattern>) {
    return {
      respond: async (response: RouteResponse) => {
        await this.operation("route.mock", { ...pattern, response: encodeRouteResponse(response) });
        this.patterns.push(pattern);
        await Promise.all([...this.contexts].map((context) => this.installRouteMocks(context)));
      },
      fault: (response: Omit<RouteResponse, "abort" | "networkError">) =>
        this.operation("route.fault", { ...pattern, response: encodeRouteResponse(response) }),
    };
  }

  private async installRouteMocks(context: BrowserContext) {
    if (this.routeContexts.has(context)) return;
    this.routeContexts.add(context);
    await context.route((url) => url.origin === this.origin && !/^\/_rehearsal(?:\/|$)/.test(url.pathname), async (route) => {
      const request = route.request();
      const url = new URL(request.url());
      if (!this.patterns.some((pattern) => matchesRoute(pattern, url, request.method()))) return route.fallback();
      const rule = await claimRoute(this.origin, this.marker, request.method(), url);
      if (!rule) return route.fallback();
      const response = await mockResponse(rule);
      if (rule.abort || rule.networkError) return route.abort(rule.abort ? "aborted" : "failed");
      return route.fulfill({ ...response, headers: Object.fromEntries(response.headers) });
    });
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
  return (await response.json()) as { marker: string; resetMs: Record<string, number>; client?: Record<string, unknown> };
}
