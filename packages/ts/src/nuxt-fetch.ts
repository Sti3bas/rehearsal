import { createFetch } from "ofetch";
import { http, HttpResponse } from "msw";
import { setupServer } from "msw/node";
import { claimRoute, mockResponse } from "./routes.js";
import { attemptMarker, rehearsalSsrFetch } from "./ssr.js";

let server: ReturnType<typeof setupServer> | undefined;

function intercept() {
  if (!server) {
    server = setupServer();
    server.listen({ onUnhandledFrame: "bypass" });
  }
  return server;
}

export function createNuxtRehearsalFetch(laravelOrigin: string, requestHeaders: Headers, mocks = false) {
  const relay = rehearsalSsrFetch({ enabled: true, laravelOrigin, requestHeaders });
  const marker = mocks ? attemptMarker(requestHeaders) : undefined;
  const origin = new URL(laravelOrigin).origin;
  return createFetch({
    defaults: { baseURL: laravelOrigin },
    async fetch(input, init) {
      const headers = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined));
      headers.delete("cookie");
      const forward = () => relay(input, { ...init, headers });
      const target = new URL(input instanceof Request ? input.url : input, origin);
      if (!marker || target.origin !== origin || target.username || target.password
          || /^\/_rehearsal(?:\/|$)/.test(target.pathname)) return forward();
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
