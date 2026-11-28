export const nuxtReadyPath = "/_rehearsal/nuxt/ready";

export type NuxtRelayStatus = "ready" | "disabled" | "unsupported";

export function nuxtRelayStatus(enabled: unknown, handler: unknown): NuxtRelayStatus {
  if (enabled !== true && enabled !== "true") return "disabled";
  return typeof handler === "function" ? "ready" : "unsupported";
}

export async function checkNuxtRelay(frontend: URL, fetcher = fetch) {
  const url = new URL(frontend);
  url.pathname = frontend.pathname.replace(/\/+$/, "") + nuxtReadyPath;
  url.search = "";
  url.hash = "";
  const response = await fetcher(url, { redirect: "manual", signal: AbortSignal.timeout(5_000) }).catch((cause) => {
    throw new Error(`Could not reach the Rehearsal Nuxt SSR readiness endpoint at ${url}. Check that the Nuxt webServer and nuxtURL/baseURL point to the same server.`, { cause });
  });
  const info = response.ok ? await response.json().catch(() => null) : null;
  if (info?.rehearsalNuxt === "ready") return;
  if (info?.rehearsalNuxt === "disabled") {
    throw new Error("The Rehearsal Nuxt SSR relay is disabled. Set NUXT_REHEARSAL_ENABLED=true in the Nuxt webServer environment.");
  }
  if (info?.rehearsalNuxt === "unsupported") {
    throw new Error("The Rehearsal Nuxt SSR relay requires a Node server with Nitro 2. Use a supported Nitro version or scope the fake to the browser.");
  }
  throw new Error(`No Rehearsal Nuxt module readiness response at ${url} (HTTP ${response.status}). Add "@sti3bas/rehearsal/nuxt" to nuxt.config and rebuild or restart Nuxt; check nuxtURL/baseURL and update the npm package if needed.`);
}
