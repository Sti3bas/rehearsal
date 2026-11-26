import { afterEach, describe, expect, it, vi } from "vitest";
import plugin from "../src/nitro.plugin.mjs";

vi.mock("#imports", () => ({
  defineNitroPlugin: (setup: unknown) => setup,
  useRuntimeConfig: () => ({ rehearsal: { enabled: true, laravelOrigin: "http://127.0.0.1:8100" } }),
}));

afterEach(() => vi.restoreAllMocks());

describe("Nitro forwarding", () => {
  it("does not change fetch or throw when the Nitro app exposes no Nitro 2 handler", () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    const fetch = globalThis.fetch;
    expect(() => plugin({ h3: {} })).not.toThrow();
    expect(globalThis.fetch).toBe(fetch);
    expect(warn).toHaveBeenCalledWith(expect.stringContaining("requires Nitro 2"));
  });
});
