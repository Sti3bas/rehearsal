import { AsyncLocalStorage } from "node:async_hooks";
import { defineNitroPlugin, getRequestHeaders, useRuntimeConfig } from "#imports";
import { setNuxtRelayStatus } from "./nitro-ready.mjs";
import { nuxtProxyContext, proxyNuxtFetch } from "./nuxt-proxy.js";
import { nuxtRelayStatus } from "./nuxt-readiness.js";
import { attemptMarker } from "./ssr.js";
import { capabilityHeader, loopback, markerHeader } from "./transport.js";

export default defineNitroPlugin(({ h3App }) => {
  const { enabled, laravelOrigin } = useRuntimeConfig().rehearsal;
  const status = nuxtRelayStatus(enabled, h3App?.handler);
  setNuxtRelayStatus(status);
  if (status === "disabled") return;
  if (status === "unsupported") {
    console.warn("Rehearsal automatic Nitro forwarding requires Nitro 2; use useRehearsalFetch() for Nuxt app SSR.");
    return;
  }

  const origin = new URL(laravelOrigin);
  if (!loopback(origin)) throw new Error("Rehearsal Nitro requires a loopback Laravel origin.");
  const context = new AsyncLocalStorage();
  const handle = h3App.handler;
  h3App.handler = (event) => context.run({
    marker: attemptMarker(getRequestHeaders(event)),
    proxy: nuxtProxyContext(event.node.req),
  }, () => handle(event));

  const fetch = globalThis.fetch;
  globalThis.fetch = async (input, init) => {
    const target = URL.parse(input instanceof Request ? input.url : input);
    const state = context.getStore();
    const control = target?.origin === origin.origin && /^\/_rehearsal(?:\/|$)/.test(target.pathname);
    if (state?.proxy && target && !target.username && !target.password && !control &&
        ["http:", "https:"].includes(target.protocol)) {
      const mocked = await proxyNuxtFetch(fetch, state.proxy, input, init);
      if (mocked) return mocked;
    }
    if (target?.origin !== origin.origin || target.username || target.password || control) return fetch(input, init);

    const marker = state?.marker;
    if (!marker) return fetch(input, init);

    const headers = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined));
    headers.delete(capabilityHeader);
    headers.set(markerHeader, marker);
    return fetch(input, { ...init, headers, redirect: "manual" });
  };
});
