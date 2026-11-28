import { setResponseHeader } from "#imports";

/** @type {import("./nuxt-readiness.js").NuxtRelayStatus} */
let status = "unsupported";

/** @param {import("./nuxt-readiness.js").NuxtRelayStatus} value */
export function setNuxtRelayStatus(value) {
  status = value;
}

export default function nuxtReady(event) {
  setResponseHeader(event, "cache-control", "no-store");
  return { rehearsalNuxt: status };
}
