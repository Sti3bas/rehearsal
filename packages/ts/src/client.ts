import { randomUUID } from "node:crypto";
import type { APIRequestContext, BrowserContext, Page } from "@playwright/test";
import { createFactoryBuilder, serializeFactoryArg, tagModel } from "./factory.js";
import { FakeBridge } from "./fake-bridge.js";
import { encodeHttpRules, type HttpFakeRules } from "./http.js";
import { Mailbox } from "./mail.js";
import { createModelQuery } from "./model.js";
import { Queue, type DrainOptions, type DrainResult } from "./queue.js";
import { capabilityHeader, loopback, markerCookie, markerHeader, protocol } from "./transport.js";
import { claimRoute, encodeRouteResponse, fakeRoutePattern, matchesRoute, mockResponse, routePattern, wait, waitForRouteGate, type FakeCallback, type FakeResponse, type RouteResponse, type RouteTarget } from "./routes.js";
import type { ArtisanInput, ArtisanName, BatchChecks, BatchFactory, BatchResults, FactoryAttributes, FactoryBuilder, FactoryModel, FactoryName, FactoryOptions, FactoryState, FakeArguments, FakeTarget, InputArguments, ModelQuery, OperationInput, OperationName, OperationOutput, ScheduleName } from "./schema.js";

export type FactoryCall =
  | { state: string; args?: readonly unknown[] }
  | { attrs: Record<string, unknown> }
  | { for: Record<string, unknown>; relation?: string };

export interface FactoryDefinition {
  model: string;
  attrs?: Record<string, unknown>;
  states?: readonly (string | { name: string; args?: readonly unknown[] })[];
  calls?: readonly FactoryCall[];
  count?: number;
  refs?: Record<string, number>;
}

export interface TypesenseClientConfig {
  origin: string;
  collectionPrefix: string;
  searchKey: string;
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
  private fakeBridge = new FakeBridge();
  private callbackFailure?: { error: unknown };
  readonly queue = new Queue(this);
  readonly http = {
    fake: (rules: HttpFakeRules, options: { preventStrayRequests?: boolean } = {}) =>
      this.operation<null>("http.fake", { rules: encodeHttpRules(rules), ...options }),
  };
  readonly schedule = {
    run: async (names: ScheduleName | readonly ScheduleName[], options: { drain?: DrainOptions } = {}) => {
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

  typesense(): TypesenseClientConfig {
    const scout = Object.values(this.metadata).find((value): value is TypesenseClientConfig => {
      const candidate = value as (Partial<TypesenseClientConfig> & { engine?: unknown }) | null;
      return candidate?.engine === "typesense" && typeof candidate.origin === "string"
        && typeof candidate.collectionPrefix === "string" && typeof candidate.searchKey === "string";
    });
    if (!scout) throw new Error("Rehearsal Typesense is not enabled.");
    const { origin, collectionPrefix, searchKey } = scout;
    return { origin, collectionPrefix, searchKey };
  }

  mailbox(recipient: string) {
    return new Mailbox(this, recipient);
  }

  async bindContext(context: BrowserContext, { primary = true } = {}) {
    const origins = [new URL(this.origin), new URL(this.frontendOrigin)];
    if (!origins.every(loopback)) {
      throw new Error("Rehearsal attempt cookies require loopback origins.");
    }
    const hosts = new Map(origins.map((url) => [url.hostname, url.origin]));
    await context.addCookies([...hosts.values()].map((origin) => ({
      name: markerCookie, value: this.marker, url: origin + "/", httpOnly: true, sameSite: "Lax",
    })));
    if (primary) this.context ??= context;
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

  async clearRouteMocks() {
    await this.operation("route.mock.clear", {});
    this.patterns = [];
  }

  async clearFakes() {
    await this.operation<null>("route.fake.clear", {});
    this.fakeBridge.clear();
  }

  async disposeFakes() {
    await this.fakeBridge.close();
    if (this.callbackFailure) throw this.callbackFailure.error;
  }

  fake<const T extends FakeTarget>(target: T, ...args: FakeArguments<NoInfer<T>>): Promise<null>;
  async fake(target: FakeTarget, response: FakeResponse | readonly FakeResponse[] | FakeCallback) {
    const pattern = fakeRoutePattern(target, this.origin);
    if (typeof response === "function") {
      const callback = await this.fakeBridge.register(async (request) => {
      try {
        return encodeRouteResponse(await response(request));
      } catch (error) {
        const cancelled = request.signal.aborted && (error === request.signal.reason ||
          (error instanceof Error && (error.name === "AbortError" || error.cause === request.signal.reason)));
        if (!cancelled) this.callbackFailure ??= { error };
        throw error;
        }
      });
      return this.operation<null>("route.fake", { ...pattern, callback });
    }
    const responses: readonly FakeResponse[] = "length" in response ? response : [response];
    return this.operation<null>("route.fake", {
      ...pattern,
      responses: responses.map(encodeRouteResponse),
    });
  }

  private routeActions(pattern: ReturnType<typeof routePattern>) {
    const respond = async (response: RouteResponse, gate?: string) => {
      await this.operation("route.mock", { ...pattern, response: { ...encodeRouteResponse(response), gate } });
      this.patterns.push(pattern);
      await Promise.all([...this.contexts].map((context) => this.installRouteMocks(context)));
    };
    return {
      respond: (response: RouteResponse) => respond(response),
      pause: async (response: RouteResponse = { passthrough: true }) => {
        const gate = randomUUID().replaceAll("-", "");
        await respond(response, gate);
        return {
          waitForRequest: ({ timeout = 5_000 }: { timeout?: number } = {}) =>
            waitForRouteGate(this.origin, this.marker, gate, "claimed", AbortSignal.timeout(timeout)),
          release: () => this.operation("route.mock.release", { gate }),
        };
      },
    };
  }

  private async installRouteMocks(context: BrowserContext) {
    if (this.routeContexts.has(context)) return;
    this.routeContexts.add(context);
    const controller = new AbortController();
    context.once("close", () => controller.abort());
    await context.route((url) => url.origin === this.origin && !/^\/_rehearsal(?:\/|$)/.test(url.pathname), async (route) => {
      const request = route.request();
      const url = new URL(request.url());
      if (!this.patterns.some((pattern) => matchesRoute(pattern, url, request.method()))) return route.fallback();
      try {
        const rule = await claimRoute(this.origin, this.marker, request.method(), url, fetch, controller.signal);
        if (!rule) return route.fallback();
        if (rule.gate) await waitForRouteGate(this.origin, this.marker, rule.gate, "released", controller.signal);
        if (rule.delay) await wait(rule.delay, controller.signal);
        if (rule.passthrough) return route.fallback();
        if (rule.abort || rule.networkError) return route.abort(rule.abort ? "aborted" : "failed");
        const response = mockResponse(rule);
        return route.fulfill({ ...response, headers: Object.fromEntries(response.headers) });
      } catch (error) {
        if (!controller.signal.aborted) throw error;
      }
    });
  }

  async preparePage(page: Page) {
    this.pages.add(page);
    page.once("close", () => this.pages.delete(page));
    if (this.time) await page.clock.setFixedTime(this.time);
  }

  factory<K extends FactoryName>(model: K): FactoryBuilder<K>;
  factory<K extends FactoryName>(model: K, attrs: FactoryAttributes<NoInfer<K>>, options: FactoryOptions<NoInfer<K>> & { count: number }): Promise<FactoryModel<K>[]>;
  factory<K extends FactoryName>(model: K, attrs: FactoryAttributes<NoInfer<K>>, options?: FactoryOptions<NoInfer<K>>): Promise<FactoryModel<K>>;
  factory<const Items extends readonly BatchFactory[]>(items: Items & BatchChecks<Items>): Promise<BatchResults<Items>>;
  factory(model: string | readonly FactoryDefinition[], attrs?: Row, options?: Omit<FactoryDefinition, "model" | "attrs">) {
    if (Array.isArray(model)) {
      return this.sendFactories(model);
    }
    if (arguments.length === 1) {
      return createFactoryBuilder((items) => this.sendFactories(items), model as string);
    }
    return this.sendFactories([{ model: model as string, attrs: attrs ?? {}, ...options }]).then((results) => results[0]);
  }

  create<K extends FactoryName>(model: K, { state, attrs }: { state?: FactoryState<NoInfer<K>>; attrs?: FactoryAttributes<NoInfer<K>> } = {}): Promise<FactoryModel<K>> {
    return this.factory(model, attrs ?? {}, { states: state === undefined ? [] : [state] });
  }

  model<K extends FactoryName>(model: K): ModelQuery<K> {
    return createModelQuery((payload) => this.operation("model.query", payload), model);
  }

  private async sendFactories(items: readonly FactoryDefinition[]) {
    const encoded = items.map((item) => item.states
      ? {
          ...item,
          states: item.states.map((state) => typeof state === "string"
            ? state
            : { name: state.name, ...(state.args ? { args: state.args.map(serializeFactoryArg) } : {}) }),
        }
      : item);
    const results = await this.operation<FactoryResult[]>("factory", { items: encoded });
    return results.map((result, index) => tagModel(result, items[index].model));
  }

  async login(user: { id: number | string }, context = this.context) {
    if (!context) throw new Error("Rehearsal login requires a browser context fixture.");
    if (!this.contexts.has(context)) await this.bindContext(context);
    return this.post<number | string>(context.request, "login", user);
  }

  async travel(value: string | Date) {
    const time = new Date(value);
    await this.operation("travel", { time: time.toISOString() });
    this.time = time;
    await Promise.all([...this.pages].map((page) => page.clock.setFixedTime(time)));
  }

  artisan<K extends ArtisanName>(name: K, ...[args]: InputArguments<ArtisanInput<NoInfer<K>>>): Promise<{ code: number }>;
  artisan(name: string, args: object = {}) {
    return this.operation<{ code: number }>("artisan", { name, args });
  }

  call<K extends OperationName>(name: K, ...[data]: InputArguments<OperationInput<NoInfer<K>>>): Promise<OperationOutput<K>>;
  call(name: string, data: object = {}) {
    return this.operation(name, data as Row);
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
