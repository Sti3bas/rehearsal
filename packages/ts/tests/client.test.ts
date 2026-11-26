import type { APIRequestContext } from "@playwright/test";
import { describe, expect, it, vi } from "vitest";
import { Laravel, startAttempt } from "../src/client.js";
import { routePattern } from "../src/routes.js";
import { loopback } from "../src/transport.js";

describe("client", () => {
  it("starts a slot with a capability, then batches factory items once in order", async () => {
    const post = vi.fn()
      .mockResolvedValueOnce({ ok: () => true, json: async () => ({ marker: "attempt", resetMs: {} }) })
      .mockResolvedValueOnce({ ok: () => true, json: async () => ({ data: [{ name: "Ada" }, [{ name: "Grace" }]] }) });
    const request = { post } as unknown as APIRequestContext;
    const backend = "http://127.0.0.1:8100";
    const { marker } = await startAttempt(request, backend, "cap", 4);
    const client = new Laravel(backend, request, "cap", marker);
    const batch = [{ model: "User", attrs: { name: "Ada" } }, { model: "User", count: 1, states: ["staff"] }];
    expect(await client.factory(batch)).toEqual([{ name: "Ada" }, [{ name: "Grace" }]]);
    expect(post).toHaveBeenCalledTimes(2);
    expect(post).toHaveBeenNthCalledWith(1, backend + "/_rehearsal/start", expect.objectContaining({ data: { protocol: 1, slot: 4 } }));
    expect(post).toHaveBeenNthCalledWith(2, backend + "/_rehearsal/call", expect.objectContaining({
      headers: expect.objectContaining({ "X-Rehearsal-Attempt": "attempt", "X-Rehearsal-Capability": "cap" }),
      data: { name: "factory", data: { items: batch } },
      maxRedirects: 0,
    }));
  });

  it("rejects remote URLs despite similar-looking hostnames", () => {
    expect(loopback(new URL("http://127.0.0.1:8100"))).toBe(true);
    expect(loopback(new URL("http://localhost.evil.example:8100"))).toBe(false);
  });

  it("uses the root path for the configured Laravel origin", () => {
    const origin = "http://127.0.0.1:8100";
    expect(routePattern(origin, origin)).toEqual({ path: "/", method: undefined });
    expect(routePattern(origin + "/", origin)).toEqual({ path: "/", method: undefined });
    expect(() => routePattern("http://127.0.0.1:8101", origin)).toThrow("Laravel origin");
  });
});
