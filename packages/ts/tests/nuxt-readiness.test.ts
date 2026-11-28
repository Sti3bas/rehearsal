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

  it("falls back to the root mount when the configured URL points to a page", async () => {
    const fetcher = vi.fn(async (url: URL) => url.pathname === "/_rehearsal/nuxt/ready"
      ? Response.json({ rehearsalNuxt: "ready" })
      : Response.json({ status: "ready" }));
    await checkNuxtRelay(new URL("/en/home", frontend), fetcher);
    expect(fetcher.mock.calls.map(([url]) => url.pathname)).toEqual([
      "/en/home/_rehearsal/nuxt/ready",
      "/en/_rehearsal/nuxt/ready",
      "/_rehearsal/nuxt/ready",
    ]);
  });

  it("uses the most specific matching mount, even when its relay is disabled", async () => {
    const fetcher = vi.fn(async (url: URL) => Response.json({
      rehearsalNuxt: url.pathname.startsWith("/store/") ? "disabled" : "ready",
    }));
    await expect(checkNuxtRelay(new URL("/store/", frontend), fetcher)).rejects.toThrow("SSR relay is disabled");
    expect(fetcher).toHaveBeenCalledExactlyOnceWith(new URL("/store/_rehearsal/nuxt/ready", frontend), expect.anything());
  });

  it("keeps probing when a candidate drops the connection", async () => {
    const fetcher = vi.fn(async (url: URL) => {
      if (url.pathname.startsWith("/store/en/")) throw new TypeError("fetch failed");
      return Response.json({ rehearsalNuxt: "ready" });
    });
    await expect(checkNuxtRelay(new URL("/store/en", frontend), fetcher)).resolves.toBeUndefined();
    expect(fetcher.mock.calls.map(([url]) => url.pathname)).toEqual([
      "/store/en/_rehearsal/nuxt/ready", "/store/_rehearsal/nuxt/ready",
    ]);
  });

  it("probes only the explicit mount, even if that endpoint is missing", async () => {
    const fetcher = vi.fn(async () => new Response(null, { status: 404 }));
    await expect(checkNuxtRelay(new URL("/elsewhere", frontend), fetcher, "/store/")).rejects.toThrow("HTTP 404");
    expect(fetcher).toHaveBeenCalledExactlyOnceWith(new URL("/store/_rehearsal/nuxt/ready", frontend), expect.anything());
  });

  it.each(["/store", "/store/", "/store/?ignored=1#fragment", "/store/en/home"])("finds a Nuxt mount under %s", async (base) => {
    const endpoint = new URL("/store/_rehearsal/nuxt/ready", frontend);
    const fetcher = vi.fn(async (url: URL) =>
      url.href === endpoint.href ? Response.json({ rehearsalNuxt: "ready" }) : new Response(null, { status: 404 }));

    await expect(checkNuxtRelay(new URL(base, frontend), fetcher)).resolves.toBeUndefined();
    expect(fetcher).toHaveBeenCalledWith(endpoint, expect.objectContaining({ redirect: "manual" }));
  });
});
