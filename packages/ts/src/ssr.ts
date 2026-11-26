import { capabilityHeader, loopback, markerCookie, markerHeader } from "./transport.js";

interface SsrOptions {
  enabled: boolean;
  laravelOrigin: string;
  requestHeaders: HeadersInit;
}

const signedMarker = /^[a-f0-9]{24}\.[1-9][0-9]?\.[a-f0-9]{32}\.[0-9]{10}\.[a-f0-9]{64}$/;

export function rehearsalSsrFetch({ enabled, laravelOrigin, requestHeaders }: SsrOptions) {
  const origin = new URL(laravelOrigin);
  if (enabled && !loopback(origin)) throw new Error("Rehearsal SSR requires a loopback Laravel origin.");
  const received = new Headers(requestHeaders);
  const cookie = received.get("cookie")?.split(";").map((part) => part.trim())
    .find((part) => part.startsWith(markerCookie + "="))?.slice(markerCookie.length + 1);
  const marker = received.get(markerHeader) ?? cookie;

  return (path: string, init: RequestInit = {}) => {
    const target = new URL(path, origin);
    if (target.origin !== origin.origin || target.username || target.password) {
      throw new Error("Rehearsal SSR fetch can only call its configured Laravel origin.");
    }
    const headers = new Headers(init.headers);
    headers.delete(markerHeader);
    headers.delete(capabilityHeader);
    if (enabled && marker && signedMarker.test(marker)) headers.set(markerHeader, marker);

    return fetch(target, { ...init, headers, redirect: "manual" });
  };
}
