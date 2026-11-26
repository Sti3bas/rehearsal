import { createFetch } from "ofetch";
import { rehearsalSsrFetch } from "./ssr.js";

export function createNuxtRehearsalFetch(laravelOrigin: string, requestHeaders: Headers) {
  const relay = rehearsalSsrFetch({ enabled: true, laravelOrigin, requestHeaders });
  return createFetch({
    defaults: { baseURL: laravelOrigin },
    fetch(input, init) {
      const headers = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined));
      headers.delete("cookie");
      return relay(input, { ...init, headers });
    },
  });
}
