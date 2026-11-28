import { describe, expect, it, vi } from "vitest";
import { proxyNuxtFetch } from "../src/nuxt-proxy.js";

const context = { port: 1234, token: "bridge", id: "test" };

describe("Nuxt fake proxy", () => {
  it("leaves the original Request body readable after passthrough or a miss", async () => {
    const request = new Request("https://example.test/post", { method: "POST", body: "original body" });
    const fetch = vi.fn(async () => Response.json(null));
    await expect(proxyNuxtFetch(fetch, context, request)).resolves.toBeNull();
    expect(request.bodyUsed).toBe(false);
    expect(await request.text()).toBe("original body");
  });
});
