import { afterEach, expect, it, vi } from "vitest";
import { createNuxtRehearsalFetch } from "../src/nuxt-fetch.js";
import { rehearsalSsrFetch } from "../src/ssr.js";

const marker = "a".repeat(24) + ".3." + "b".repeat(32) + ".1999999999." + "c".repeat(64);
const laravelOrigin = "http://127.0.0.1:8100";

afterEach(() => vi.unstubAllGlobals());

it("relays only the attempt from the request cookie and does not follow redirects", async () => {
  const fetch = vi.fn();
  vi.stubGlobal("fetch", fetch);
  const relay = rehearsalSsrFetch({
    enabled: true, laravelOrigin,
    requestHeaders: { cookie: "session=private; rehearsal_attempt=" + marker, "X-Rehearsal-Capability": "never" },
  });
  await relay("/state");
  expect(fetch.mock.calls[0][0].href).toBe(laravelOrigin + "/state");
  expect([...fetch.mock.calls[0][1].headers]).toEqual([["x-rehearsal-attempt", marker]]);
  expect(fetch.mock.calls[0][1].redirect).toBe("manual");
  expect(() => relay("//127.0.0.1:8101/state")).toThrow("configured Laravel origin");
});

it("does not forward routing credentials when disabled", async () => {
  const fetch = vi.fn();
  vi.stubGlobal("fetch", fetch);
  const relay = rehearsalSsrFetch({ enabled: false, laravelOrigin, requestHeaders: { "X-Rehearsal-Attempt": marker } });
  await relay("/state", { headers: { "X-Rehearsal-Attempt": marker, "X-Rehearsal-Capability": "never" } });
  expect([...fetch.mock.calls[0][1].headers]).toEqual([]);
});

it("adapts ofetch without forwarding cookies, capabilities, or credentials to another origin", async () => {
  const fetch = vi.fn(() => Promise.resolve(Response.json({ users: ["Ada"] })));
  vi.stubGlobal("fetch", fetch);
  const fetchLaravel = createNuxtRehearsalFetch(laravelOrigin, new Headers({
    cookie: "rehearsal_attempt=" + marker + "; session=private",
  }));
  await expect(fetchLaravel("/state", {
    query: { active: true },
    headers: { cookie: "session=private", "x-rehearsal-capability": "never" },
  })).resolves.toEqual({ users: ["Ada"] });
  expect(fetch).toHaveBeenCalledWith(new URL(laravelOrigin + "/state?active=true"), {
    method: undefined,
    headers: new Headers({ "x-rehearsal-attempt": marker }),
    redirect: "manual",
    baseURL: laravelOrigin,
  });
  await expect(fetchLaravel("http://127.0.0.1:8101/state", { retry: false })).rejects.toThrow("configured Laravel origin");
  expect(fetch).toHaveBeenCalledTimes(1);
});
