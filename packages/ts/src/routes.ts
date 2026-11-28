import { markerHeader } from "./transport.js";

export interface RouteResponse {
  status?: number;
  json?: unknown;
  body?: string;
  headers?: Record<string, string>;
  delay?: number;
  abort?: boolean;
  networkError?: boolean;
  once?: boolean;
  times?: number;
  passthrough?: boolean;
}

export type RouteTarget = string | { url: string; method: string };
export type FakeMethod = "GET" | "POST" | "PUT" | "PATCH" | "DELETE" | "HEAD" | "OPTIONS";
export type FakeRoute = { url: string; method: FakeMethod | Lowercase<FakeMethod> };
export type FakeResponse<Json = unknown> = Pick<RouteResponse, "status" | "headers" | "delay" | "once" | "times"> & (
  { json?: Json; body?: never } | { json?: never; body?: string }
);
export type FakeCallback<Json = unknown> = (request: Request) => FakeResponse<Json> | Promise<FakeResponse<Json>>;

export function fakeRoutePattern(target: string | FakeRoute, origin: string) {
  if (typeof target !== "string") return routePattern(target, origin);
  const separator = target.indexOf(" ");
  if (separator === -1) throw new Error('Rehearsal fakes require a method and path, such as "GET /api/feed".');
  return routePattern({ method: target.slice(0, separator), url: target.slice(separator + 1) }, origin);
}
type EncodedRouteResponse = Omit<RouteResponse, "json"> & { jsonBody?: string; gate?: string };

export function encodeRouteResponse({ json, ...response }: RouteResponse): EncodedRouteResponse {
  if (json === undefined) return response;
  const jsonBody = JSON.stringify(json);
  if (jsonBody === undefined) throw new Error("Rehearsal route mocks need a JSON-serializable body.");
  return { ...response, jsonBody };
}

export function routePattern(target: RouteTarget, origin: string) {
  const path = typeof target === "string" ? target : target.url;
  const absolute = path === origin ? "/" : path.startsWith(origin + "/") ? path.slice(origin.length) : path;
  if (!absolute.startsWith("/") || absolute.startsWith("//")) {
    throw new Error("Rehearsal route mocks require a path or a URL at the Laravel origin.");
  }
  return { path: absolute, method: typeof target === "string" ? undefined : target.method.toUpperCase() };
}

export function matchesRoute(rule: ReturnType<typeof routePattern>, url: URL, method: string) {
  const regex = rule.path.split(/(\*\*|\*)/).map((part) =>
    part === "**" ? ".*" : part === "*" ? "[^/]*" : part.replace(/[|\\{}()[\]^$+?.]/g, "\\$&"),
  ).join("");
  return (!rule.method || rule.method === method) &&
    new RegExp("^" + regex + "$").test(url.pathname + (rule.path.includes("?") ? url.search : ""));
}

export async function claimRoute(origin: string, marker: string, method: string, url: URL, fetcher = fetch, signal?: AbortSignal): Promise<EncodedRouteResponse | null> {
  const probe = new URL("/_rehearsal/route-mock", origin);
  probe.search = new URLSearchParams({ method, path: url.pathname + url.search }).toString();
  const response = await fetcher(probe, { headers: { [markerHeader]: marker }, redirect: "manual", ...(signal ? { signal } : {}) });
  if (!response.ok) throw new Error("Rehearsal route lookup failed (HTTP " + response.status + ").");
  return (await response.json()).data as EncodedRouteResponse | null;
}

export async function waitForRouteGate(origin: string, marker: string, gate: string, state: "claimed" | "released", signal?: AbortSignal) {
  const url = new URL("/_rehearsal/route-mock/gates/" + gate, origin);
  while (true) {
    signal?.throwIfAborted();
    const response = await fetch(url, { headers: { [markerHeader]: marker }, redirect: "manual", signal });
    if (!response.ok) throw new Error("Rehearsal route gate lookup failed (HTTP " + response.status + ").");
    if ((await response.json()).data[state] === true) return;
    await wait(50, signal);
  }
}

export function wait(delay: number, signal?: AbortSignal) {
  signal?.throwIfAborted();
  return new Promise<void>((resolve, reject) => {
    const abort = () => {
      clearTimeout(timer);
      reject(signal?.reason);
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener("abort", abort);
      resolve();
    }, delay);
    signal?.addEventListener("abort", abort, { once: true });
  });
}

export function mockResponse(rule: EncodedRouteResponse) {
  const headers = new Headers(rule.headers);
  if (rule.jsonBody !== undefined && !headers.has("content-type")) headers.set("content-type", "application/json");
  const body = [204, 205, 304].includes(rule.status ?? 200) ? undefined : rule.jsonBody ?? rule.body;
  return { status: rule.status ?? 200, headers, body };
}
