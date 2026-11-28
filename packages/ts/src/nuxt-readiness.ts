export const nuxtReadyPath = "/_rehearsal/nuxt/ready";

export type NuxtRelayStatus = "ready" | "disabled" | "unsupported";

export function nuxtRelayStatus(enabled: unknown, handler: unknown): NuxtRelayStatus {
  if (enabled !== true && enabled !== "true") return "disabled";
  return typeof handler === "function" ? "ready" : "unsupported";
}

export async function checkNuxtRelay(frontend: URL, fetcher = fetch) {
  const mounts = [""];
  for (let path = frontend.pathname.replace(/\/+$/, ""); path; path = path.slice(0, path.lastIndexOf("/"))) {
    mounts.push(path);
  }

  const url = new URL(frontend);
  url.search = "";
  url.hash = "";
  const signal = AbortSignal.timeout(5_000);
  const checked = [];
  for (const mount of mounts) {
    const endpoint = new URL(url);
    endpoint.pathname = mount + nuxtReadyPath;
    const response = await fetcher(endpoint, { redirect: "manual", signal }).catch((cause) => {
      throw new Error(`Could not reach the Rehearsal Nuxt SSR readiness endpoint at ${endpoint}. Check that the Nuxt webServer and nuxtURL/baseURL point to the same server.`, { cause });
    });
    const info = response.ok ? await response.json().catch(() => null) : null;
    if (info?.rehearsalNuxt === "ready") return;
    if (info?.rehearsalNuxt === "disabled") {
      throw new Error("The Rehearsal Nuxt SSR relay is disabled. Set NUXT_REHEARSAL_ENABLED=true in the Nuxt webServer environment.");
    }
    if (info?.rehearsalNuxt === "unsupported") {
      throw new Error("The Rehearsal Nuxt SSR relay requires a Node server with Nitro 2. Use a supported Nitro version or scope the fake to the browser.");
    }
    void response.body?.cancel().catch(() => {});
    checked.push(`${endpoint} (HTTP ${response.status})`);
  }
  throw new Error(`No Rehearsal Nuxt module readiness response at ${checked.join(", ")}. Add "@sti3bas/rehearsal/nuxt" to nuxt.config and rebuild or restart Nuxt; check nuxtURL/baseURL and update the npm package if needed.`);
}
