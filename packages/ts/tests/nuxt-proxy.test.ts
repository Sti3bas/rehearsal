import { describe, expect, it, vi } from "vitest";
import { FakeBridge } from "../src/fake-bridge.js";
import { proxyNuxtFetch } from "../src/nuxt-proxy.js";

const context = { port: 1234, token: "bridge", id: "test" };

describe("Nuxt fake proxy", () => {
  it("only sends the body when the matched callback needs one", async () => {
    const bridge = new FakeBridge();
    try {
      const matches = (url: string) => new URL(url).pathname === "/matched";
      const connection = await bridge.register(async (request) => matches(request.url) ? { body: await request.text() } : null,
        ({ url }) => matches(url));
      const upload = new Request("https://example.test/unrelated", { method: "POST", body: "a".repeat(2_100_000) });
      await expect(proxyNuxtFetch(fetch, connection, upload)).resolves.toBeNull();
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
});
