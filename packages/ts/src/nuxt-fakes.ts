import type { Page, Request as BrowserRequest, Route } from "@playwright/test";
import { FakeBridge, fakeBridgeHeader } from "./fake-bridge.js";
import { encodeRouteResponse, mockResponse, wait, type FakeMethod, type FakeResponse } from "./routes.js";
import { capabilityHeader, loopback, markerHeader } from "./transport.js";

export const passthrough = Symbol("rehearsal.nuxt.passthrough");
export type NuxtFakePattern = `${FakeMethod} ${"http" | "https"}://${string}`;
export type NuxtFakeResponse<Json = unknown> = (FakeResponse<Json> & { once?: never; times?: never }) | typeof passthrough;
export type NuxtFakeHandler<Json = unknown> = (request: Request) => NuxtFakeResponse<Json> | Promise<NuxtFakeResponse<Json>>;
export type NuxtFakeRule<Json = unknown> = NuxtFakeResponse<Json> | readonly NuxtFakeResponse<Json>[] | NuxtFakeHandler<Json>;
export type NuxtFakeRules = Partial<Record<NuxtFakePattern, NuxtFakeRule>>;

interface Rule {
  method: string;
  url: RegExp;
  includesQuery: boolean;
  needsBody: boolean;
  handler: NuxtFakeHandler;
}

export class NuxtFakes {
  private rules: Rule[] = [];
  private bridge = new FakeBridge();
  private connection?: Promise<{ port: number; token: string; id: string }>;
  private routePattern = /^https?:\/\//;
  private frontend: URL;

  constructor(private page: Page, frontend: string) {
    this.frontend = new URL(frontend);
    if (!loopback(this.frontend)) throw new Error("Rehearsal Nuxt fakes require a loopback frontend URL.");
  }

  async fake(rules: NuxtFakeRules) {
    this.rules.unshift(...Object.entries(rules).flatMap(([key, response]) => response === undefined ? [] : [this.rule(key, response)]).reverse());
    await (this.connection ??= this.start());
  }

  clearFakes() {
    this.rules = [];
  }

  async close() {
    try {
      if (this.connection) await this.page.unroute(this.routePattern, this.intercept);
    } finally {
      await this.bridge.close();
    }
  }

  private rule(key: string, response: NuxtFakeRule): Rule {
    const match = /^(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS) (https?:\/\/[^\s]+)$/.exec(key);
    if (!match) throw new Error('Rehearsal Nuxt fakes require a method and absolute URL, such as "GET https://example.test/feed".');
    const [, method, url] = match;
    const escapePattern = (part: string) => part.split(/(\*)/).map((chunk) => chunk === "*" ? ".*" : chunk.replace(/[|\\{}()[\]^$+?.]/g, "\\$&")).join("");
    const root = /^(https?:\/\/[^/?#]+)(.*)$/.exec(url);
    const pattern = root && !root[2].startsWith("/")
      ? escapePattern(root[1]) + "/?" + escapePattern(root[2])
      : escapePattern(url);
    let calls = 0;
    const sequence: readonly NuxtFakeResponse[] = typeof response === "function" ? [] :
      typeof response !== "symbol" && "length" in response ? response : [response];
    if (!sequence.length && typeof response !== "function") throw new Error("Rehearsal Nuxt fake sequences cannot be empty.");
    return {
      method, url: new RegExp("^" + pattern + "$"), includesQuery: url.includes("?"), needsBody: typeof response === "function",
      handler: typeof response === "function" ? response : () => sequence[Math.min(calls++, sequence.length - 1)]!,
    };
  }

  private match(method: string, url: URL) {
    return this.rules.find((rule) =>
      rule.method === method && rule.url.test(url.origin + url.pathname + (rule.includesQuery ? url.search : "")),
    );
  }

  private async resolve(rule: Rule, request: Request) {
    const reply = await rule.handler(request);
    if (reply === passthrough) return null;
    return encodeRouteResponse(reply);
  }

  private async start() {
    const connection = await this.bridge.register(async (request) => {
      const rule = this.match(request.method, new URL(request.url));
      return rule ? this.resolve(rule, request) : null;
    }, ({ method, url }) => this.match(method, new URL(url))?.needsBody === true);
    await this.page.route(this.routePattern, this.intercept);
    return connection;
  }

  private intercept = async (route: Route, request: BrowserRequest) => {
    const url = new URL(request.url());
    const rule = this.match(request.method(), url);
    const headers = new Headers(await request.allHeaders());
    headers.delete(fakeBridgeHeader);
    if (rule) {
      const input = new Headers(headers);
      input.delete(markerHeader);
      input.delete(capabilityHeader);
      const body = request.postDataBuffer();
      const reply = await this.resolve(rule, new Request(url, {
        method: request.method(), headers: input, body: body ? Uint8Array.from(body) : undefined,
      }));
      if (reply) {
        if (reply.delay) await wait(reply.delay);
        const { status, headers: responseHeaders, body } = mockResponse(reply);
        return route.fulfill({ status, headers: Object.fromEntries(responseHeaders), body });
      }
    }
    if (url.origin !== this.frontend.origin || !this.rules.length) return route.fallback();
    const { port, token, id } = await this.connection!;
    headers.set(fakeBridgeHeader, [port, token, id].join("."));
    const response = await route.fetch({ headers: Object.fromEntries(headers), maxRedirects: 0 });
    try {
      await route.fulfill({ response });
    } finally {
      await response.dispose();
    }
  };
}
