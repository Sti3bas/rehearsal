import { describe, expect, it, vi } from "vitest";
import { checkNuxtRelay, nuxtRelayStatus } from "../src/nuxt-readiness.js";

const frontend = new URL("http://127.0.0.1:3000");

describe("Nuxt SSR readiness", () => {
  it.each([
    [false, () => {}, "disabled"],
    [true, undefined, "unsupported"],
    ["true", () => {}, "ready"],
  ])("reports the relay status for enabled=%s", (enabled, handler, status) => {
    expect(nuxtRelayStatus(enabled, handler)).toBe(status);
  });

  it("recognizes the module and reports what's preventing SSR interception", async () => {
    await expect(checkNuxtRelay(frontend, vi.fn(async () => Response.json({ rehearsalNuxt: "ready" })))).resolves.toBeUndefined();
    await expect(checkNuxtRelay(frontend, vi.fn(async () => Response.json({ rehearsalNuxt: "disabled" })))).rejects.toThrow("NUXT_REHEARSAL_ENABLED=true");
    await expect(checkNuxtRelay(frontend, vi.fn(async () => Response.json({ rehearsalNuxt: "unsupported" })))).rejects.toThrow("Nitro 2");
    await expect(checkNuxtRelay(frontend, vi.fn(async () => new Response("<html>SPA fallback</html>")))).rejects.toThrow('Add "@sti3bas/rehearsal/nuxt"');
  });

  it.each(["/store", "/store/", "/store/?ignored=1#fragment"])("probes under the configured Nuxt base %s", async (base) => {
    const endpoint = new URL("/store/_rehearsal/nuxt/ready", frontend);
    const fetcher = vi.fn(async (url: URL) =>
      url.href === endpoint.href ? Response.json({ rehearsalNuxt: "ready" }) : new Response(null, { status: 404 }));

    await expect(checkNuxtRelay(new URL(base, frontend), fetcher)).resolves.toBeUndefined();
    expect(fetcher).toHaveBeenCalledWith(endpoint, expect.objectContaining({ redirect: "manual" }));
  });
});
