import { describe, expect, it, vi } from "vitest";
import { FakeBridge } from "../src/fake-bridge.js";
import { proxyNuxtFetch } from "../src/nuxt-proxy.js";

const context = { port: 1234, token: "bridge", id: "test" };

describe("Nuxt fake proxy", () => {
  it("rejects a network-error reply instead of returning an HTTP response", async () => {
    const fetch = vi.fn(async () => Response.json({ networkError: true }));
    await expect(proxyNuxtFetch(fetch, context, "https://example.test/feed")).rejects.toThrow(TypeError);
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it("only sends the body when the matched callback needs one", async () => {
    const bridge = new FakeBridge();
    try {
      const matches = (url: string) => new URL(url).pathname === "/matched";
      const connection = await bridge.register(async (request) => matches(request.url) ? { body: await request.text() } : null,
        ({ url }) => matches(url));
      const upload = new Request("https://example.test/unrelated", { method: "POST", body: "a".repeat(2_100_000) });
      const transport = vi.fn((...args: Parameters<typeof fetch>) => fetch(...args));
      await expect(proxyNuxtFetch(transport, connection, upload)).resolves.toBeNull();
      expect(transport).toHaveBeenCalledTimes(1);
      expect(JSON.parse(String(transport.mock.calls[0][1]?.body))).not.toHaveProperty("body");
      expect(upload.bodyUsed).toBe(false);
      const matched = await proxyNuxtFetch(fetch, connection, "https://example.test/matched", { method: "POST", body: "matched upload" });
      expect(await matched?.text()).toBe("matched upload");
    } finally {
      await bridge.close();
    }
  });

  it("leaves the original Request body readable after passthrough or a miss", async () => {
    const request = new Request("https://example.test/post", { method: "POST", body: "original body" });
    const fetch = vi.fn(async () => Response.json(null));
    await expect(proxyNuxtFetch(fetch, context, request)).resolves.toBeNull();
    expect(request.bodyUsed).toBe(false);
    expect(await request.text()).toBe("original body");
  });

  it("accepts a replacement body even when the input Request has been consumed", async () => {
    const request = new Request("https://example.test/post", { method: "POST", body: "old" });
    await request.text();
    const fetch = vi.fn<typeof globalThis.fetch>().mockResolvedValueOnce(new Response(null, { status: 202 }))
      .mockResolvedValueOnce(Response.json(null));
    await expect(proxyNuxtFetch(fetch, context, request, { body: "replacement" })).resolves.toBeNull();
    const wire = JSON.parse(String(fetch.mock.calls[1][1]?.body));
    expect(Buffer.from(wire.body, "base64").toString()).toBe("replacement");
  });
});
