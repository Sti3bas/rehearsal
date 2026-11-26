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
}

export type RouteTarget = string | { url: string; method: string };
type EncodedRouteResponse = Omit<RouteResponse, "json"> & { jsonBody?: string };

export function encodeRouteResponse({ json, ...response }: RouteResponse): EncodedRouteResponse {
  if (json === undefined) return response;
  const jsonBody = JSON.stringify(json);
  if (jsonBody === undefined) throw new Error("Rehearsal route mocks need a JSON-serializable body.");
  return { ...response, jsonBody };
}

export function routePattern(target: RouteTarget, origin: string) {
  const path = typeof target === "string" ? target : target.url;
  const absolute = path.startsWith(origin + "/") ? path.slice(origin.length) : path;
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

export async function claimRoute(origin: string, marker: string, method: string, url: URL, fetcher = fetch): Promise<EncodedRouteResponse | null> {
  const probe = new URL("/_rehearsal/route-mock", origin);
  probe.search = new URLSearchParams({ method, path: url.pathname + url.search }).toString();
  const response = await fetcher(probe, { headers: { [markerHeader]: marker }, redirect: "manual" });
  if (!response.ok) throw new Error("Rehearsal route lookup failed (HTTP " + response.status + ").");
  return (await response.json()).data as EncodedRouteResponse | null;
}

export async function mockResponse(rule: EncodedRouteResponse) {
  if (rule.delay) await new Promise((resolve) => setTimeout(resolve, rule.delay));
  const headers = new Headers(rule.headers);
  if (rule.jsonBody !== undefined && !headers.has("content-type")) headers.set("content-type", "application/json");
  const body = [204, 205, 304].includes(rule.status ?? 200) ? undefined : rule.jsonBody ?? rule.body;
  return { status: rule.status ?? 200, headers, body };
}
