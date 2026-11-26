import { defineNuxtPlugin, useRequestHeaders, useRequestURL, useResponseHeader, useRuntimeConfig, useState } from "#imports";
import { createNuxtRehearsalFetch } from "./nuxt-fetch.js";
import { attemptMarker, rehearsalSsrFetch } from "./ssr.js";

export default defineNuxtPlugin(async () => {
  const { enabled, laravelOrigin, forwardCookies, typesense } = useRuntimeConfig().rehearsal;
  if (enabled !== true && enabled !== "true") return;

  const headers = new Headers();
  for (const [name, value] of Object.entries(useRequestHeaders(["cookie", "x-rehearsal-attempt"]))) {
    if (typeof value === "string") headers.set(name, value);
  }
  const rehearsalFetch = createNuxtRehearsalFetch(laravelOrigin, headers, true, {
    names: forwardCookies, frontendOrigin: useRequestURL().origin,
  });
  if ((typesense === true || typesense === "true") && attemptMarker(headers)) {
    const state = useState("rehearsal:typesense");
    useResponseHeader("Cache-Control").value = "private, no-store";
    const relay = rehearsalSsrFetch({ enabled: true, laravelOrigin, requestHeaders: headers });
    const response = await relay("/_rehearsal/typesense");
    if (!response.ok) throw new Error("Could not load Rehearsal Typesense credentials (" + response.status + ").");
    state.value = await response.json();
  }
  return { provide: { rehearsalFetch } };
});
