import { defineNuxtPlugin, useRequestHeaders, useRuntimeConfig } from "#imports";
import { createNuxtRehearsalFetch } from "./nuxt-fetch.js";

export default defineNuxtPlugin(() => {
  const { enabled, laravelOrigin } = useRuntimeConfig().rehearsal;
  if (enabled !== true && enabled !== "true") return;

  const headers = new Headers();
  for (const [name, value] of Object.entries(useRequestHeaders(["cookie", "x-rehearsal-attempt"]))) {
    if (typeof value === "string") headers.set(name, value);
  }
  return { provide: { rehearsalFetch: createNuxtRehearsalFetch(laravelOrigin, headers) } };
});
