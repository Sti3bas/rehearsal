import { AsyncLocalStorage } from "node:async_hooks";
import { defineNitroPlugin, getRequestHeaders, useRuntimeConfig } from "#imports";
import { attemptMarker } from "./ssr.js";
import { capabilityHeader, loopback, markerHeader } from "./transport.js";

export default defineNitroPlugin(({ h3App }) => {
  const { enabled, laravelOrigin } = useRuntimeConfig().rehearsal;
  if (enabled !== true && enabled !== "true") return;

  const origin = new URL(laravelOrigin);
  if (!loopback(origin)) throw new Error("Rehearsal Nitro requires a loopback Laravel origin.");
  const context = new AsyncLocalStorage();
  const handle = h3App.handler;
  h3App.handler = (event) => context.run(attemptMarker(getRequestHeaders(event)), () => handle(event));

  const fetch = globalThis.fetch;
  globalThis.fetch = (input, init) => {
    const target = URL.parse(input instanceof Request ? input.url : input);
    if (target?.origin !== origin.origin || target.username || target.password ||
        /^\/_rehearsal(?:\/|$)/.test(target.pathname)) return fetch(input, init);

    const marker = context.getStore();
    if (!marker) return fetch(input, init);

    const headers = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined));
    headers.delete(capabilityHeader);
    headers.set(markerHeader, marker);
    return fetch(input, { ...init, headers, redirect: "manual" });
  };
});
