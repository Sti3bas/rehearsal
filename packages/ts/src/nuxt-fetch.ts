import { createFetch } from "ofetch";
import { HttpResponse } from "msw";
import { claimRoute, mockResponse, wait, waitForRouteGate } from "./routes.js";
import { attemptMarker, rehearsalSsrFetch } from "./ssr.js";
import { capabilityHeader, loopback, markerCookie } from "./transport.js";

export function createNuxtRehearsalFetch(laravelOrigin: string, requestHeaders: Headers, mocks = false,
  cookies?: { names: string[]; frontendOrigin: string },
) {
  const relay = rehearsalSsrFetch({ enabled: true, laravelOrigin, requestHeaders });
  const marker = attemptMarker(requestHeaders);
  const origin = new URL(laravelOrigin).origin;
  let sessionCookies = "";
  if (cookies?.names.length && marker && loopback(new URL(cookies.frontendOrigin))) {
    const allowed = new Set(cookies.names.filter((name) =>
      ![markerCookie, capabilityHeader.toLowerCase()].includes(name.toLowerCase()),
    ));
    if (allowed.delete("rehearsal-session")) {
      const [run, slot] = marker.split(".");
      allowed.add(`rehearsal_${run}_${slot}`);
    }
    sessionCookies = (requestHeaders.get("cookie") ?? "").split(";").map((part) => part.trim()).filter((part) => {
      const separator = part.indexOf("=");
      return separator > 0 && allowed.has(part.slice(0, separator));
    }).join("; ");
  }
  return createFetch({
    defaults: { baseURL: laravelOrigin },
    async fetch(input, init) {
      const headers = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined));
      headers.delete("cookie");
      const target = new URL(input instanceof Request ? input.url : input, origin);
      const control = /^\/_rehearsal(?:\/|$)/.test(target.pathname);
      if (!control && sessionCookies && target.origin === origin && !target.username && !target.password) {
        headers.set("cookie", sessionCookies);
        if (!headers.has("origin") && !headers.has("referer")) headers.set("origin", cookies!.frontendOrigin);
      }
      const forward = () => relay(input, { ...init, headers });
      if (!mocks || !marker || target.origin !== origin || target.username || target.password
          || control) return forward();
      const method = (init?.method ?? (input instanceof Request ? input.method : "GET")).toUpperCase();
      const signal = init?.signal ?? (input instanceof Request ? input.signal : undefined);
      signal?.throwIfAborted();
      const rule = await claimRoute(origin, marker, method, target, fetch, signal);
      if (!rule) return forward();

      if (rule.gate) await waitForRouteGate(origin, marker, rule.gate, "released", signal);
      if (rule.delay) await wait(rule.delay, signal);
      signal?.throwIfAborted();
      if (rule.passthrough) return forward();
      if (rule.abort || rule.networkError) throw new TypeError("fetch failed");
      const { body, ...response } = mockResponse(rule);
      return new HttpResponse(body, response);
    },
  });
}
