import { createFetch } from "ofetch";
import { http, HttpResponse } from "msw";
import { setupServer } from "msw/node";
import { claimRoute, mockResponse } from "./routes.js";
import { attemptMarker, rehearsalSsrFetch } from "./ssr.js";
import { capabilityHeader, loopback, markerCookie } from "./transport.js";

let server: ReturnType<typeof setupServer> | undefined;

function intercept() {
  if (!server) {
    server = setupServer();
    server.listen({ onUnhandledFrame: "bypass" });
  }
  return server;
}

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
      const rule = await claimRoute(origin, marker, method, target);
      if (!rule) return forward();

      const msw = intercept();
      return msw.boundary(async () => {
        msw.use(http.all(({ request }) => request.method === method && request.url === target.href, async () => {
          const { body, ...response } = await mockResponse(rule);
          return rule.abort || rule.networkError ? HttpResponse.error() : new HttpResponse(body, response);
        }));
        return forward();
      })();
    },
  });
}
