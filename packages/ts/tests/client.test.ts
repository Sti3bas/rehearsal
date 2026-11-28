import { readFileSync, statSync } from "node:fs";
import type { APIRequestContext } from "@playwright/test";
import { describe, expect, it, vi } from "vitest";
import { Laravel, startAttempt } from "../src/client.js";
import { routePattern } from "../src/routes.js";
import { laravelServer } from "../src/server.js";
import { loopback } from "../src/transport.js";
import { typesenseInstantSearch } from "../src/typesense.js";

describe("client", () => {
  it("lets a Laravel callback claimed before clearFakes pass through", async () => {
    const post = vi.fn().mockResolvedValue({ ok: () => true, json: async () => ({ data: null }) });
    const client = new Laravel("http://127.0.0.1:8100", { post } as unknown as APIRequestContext, "cap", "attempt");
    const handler = vi.fn(() => ({ status: 503 }));
    await client.fake("GET /state", handler);
    const { port, token, id } = post.mock.calls[0][1].data.data.callback;
    await client.clearFakes();
    try {
      const response = await fetch(`http://127.0.0.1:${port}/_rehearsal/fake`, {
        method: "POST", headers: { "x-rehearsal-fake": token },
        body: JSON.stringify({ id, url: "http://127.0.0.1:8100/state", method: "GET", headers: [] }),
      });
      expect(response.ok).toBe(true);
      expect(await response.json()).toBeNull();
      expect(handler).not.toHaveBeenCalled();
    } finally {
      await client.disposeFakes();
    }
  });

  it("fails fixture teardown with the original Laravel fake callback error", async () => {
    const post = vi.fn().mockResolvedValue({ ok: () => true, json: async () => ({ data: null }) });
    const client = new Laravel("http://127.0.0.1:8100", { post } as unknown as APIRequestContext, "cap", "attempt");
    const error = new Error("callback assertion failed");
    await client.fake("GET /state", () => { throw error; });
    const { port, token, id } = post.mock.calls[0][1].data.data.callback;
    const response = await fetch(`http://127.0.0.1:${port}/_rehearsal/fake`, {
      method: "POST", headers: { "x-rehearsal-fake": token },
      body: JSON.stringify({ id, url: "http://127.0.0.1:8100/state", method: "GET", headers: [] }),
    });
    expect(response.status).toBe(500);
    await expect(client.disposeFakes()).rejects.toBe(error);
  });

  it("waits for a disconnected Laravel callback before checking teardown errors", async () => {
    const post = vi.fn().mockResolvedValue({ ok: () => true, json: async () => ({ data: null }) });
    const client = new Laravel("http://127.0.0.1:8100", { post } as unknown as APIRequestContext, "cap", "attempt");
    const error = new Error("late callback assertion");
    let started!: () => void;
    let release!: () => void;
    const entered = new Promise<void>((resolve) => { started = resolve; });
    const blocked = new Promise<void>((resolve) => { release = resolve; });
    await client.fake("GET /state", async () => {
      started();
      await blocked;
      throw error;
    });
    const { port, token, id } = post.mock.calls[0][1].data.data.callback;
    const controller = new AbortController();
    const pending = fetch(`http://127.0.0.1:${port}/_rehearsal/fake`, {
      method: "POST", headers: { "x-rehearsal-fake": token }, signal: controller.signal,
      body: JSON.stringify({ id, url: "http://127.0.0.1:8100/state", method: "GET", headers: [] }),
    }).catch(() => {});
    await entered;
    controller.abort();
    await pending;
    const teardown = expect(client.disposeFakes()).rejects.toBe(error);
    await new Promise<void>((resolve) => setImmediate(resolve));
    release();
    await teardown;
  });

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
    expect(post).toHaveBeenNthCalledWith(1, backend + "/_rehearsal/start", expect.objectContaining({ data: { protocol: 2, slot: 4 } }));
    expect(post).toHaveBeenNthCalledWith(2, backend + "/_rehearsal/call", expect.objectContaining({
      headers: expect.objectContaining({ "X-Rehearsal-Attempt": "attempt", "X-Rehearsal-Capability": "cap" }),
      data: { name: "factory", data: { items: batch } },
      maxRedirects: 0,
    }));
  });

  it("chains fluent factory calls in order, tags models invisibly, and batches count/createMany", async () => {
    const post = vi.fn()
      .mockResolvedValueOnce({ ok: () => true, json: async () => ({ data: [{ id: 7, name: "Seller" }] }) })
      .mockResolvedValueOnce({ ok: () => true, json: async () => ({ data: [{ id: 11, title: "Oak" }] }) })
      .mockResolvedValueOnce({ ok: () => true, json: async () => ({ data: [{ id: 12, title: "A" }, { id: 13, title: "B" }] }) })
      .mockResolvedValueOnce({ ok: () => true, json: async () => ({ data: [{ id: 14, title: "Item 0" }, { id: 15, title: "Item 1" }] }) });
    const request = { post } as unknown as APIRequestContext;
    const client = new Laravel("http://127.0.0.1:8100", request, "cap", "attempt");

    const builder = client.factory("User");
    expect((builder as unknown as { then?: unknown }).then).toBeUndefined();

    const seller = await builder.create({ name: "Seller" });
    expect(seller).toEqual({ id: 7, name: "Seller" });
    expect(Object.keys(seller)).toEqual(["id", "name"]);

    const soldAt = new Date("2026-05-01T12:00:00.000Z");
    const activeBase = client.factory("Listing").for(seller, "owner").active();
    const listing = await activeBase.state({ price: 500 }).soldTo(seller, soldAt).create({ title: "Oak" });
    expect(listing).toEqual({ id: 11, title: "Oak" });
    expect(post).toHaveBeenNthCalledWith(2, "http://127.0.0.1:8100/_rehearsal/call", expect.objectContaining({
      data: {
        name: "factory",
        data: {
          items: [{
            model: "Listing",
            calls: [
              { for: { $rehearsalModel: "User", id: 7 }, relation: "owner" },
              { state: "active", args: [] },
              { attrs: { price: 500 } },
              { state: "soldTo", args: [{ $rehearsalModel: "User", id: 7 }, "2026-05-01T12:00:00.000Z"] },
            ],
            attrs: { title: "Oak" },
          }],
        },
      },
    }));

    const pair = await activeBase.createMany([{ title: "A" }, { title: "B" }]);
    expect(pair).toEqual([{ id: 12, title: "A" }, { id: 13, title: "B" }]);
    expect(post).toHaveBeenNthCalledWith(3, "http://127.0.0.1:8100/_rehearsal/call", expect.objectContaining({
      data: {
        name: "factory",
        data: {
          items: [
            { model: "Listing", calls: [{ for: { $rehearsalModel: "User", id: 7 }, relation: "owner" }, { state: "active", args: [] }], attrs: { title: "A" } },
            { model: "Listing", calls: [{ for: { $rehearsalModel: "User", id: 7 }, relation: "owner" }, { state: "active", args: [] }], attrs: { title: "B" } },
          ],
        },
      },
    }));

    const counted = await client.factory("Listing").count(2).create((_, i) => ({ title: `Item ${i}` }));
    expect(counted).toEqual([{ id: 14, title: "Item 0" }, { id: 15, title: "Item 1" }]);
  });

  it("rejects remote URLs despite similar-looking hostnames", () => {
    expect(loopback(new URL("http://127.0.0.1:8100"))).toBe(true);
    expect(loopback(new URL("http://localhost.evil.example:8100"))).toBe(false);
  });

  it("advertises localhost for managed Sanctum setups", () => {
    const server = laravelServer({ server: "frankenphp", host: "localhost", port: 8010 });
    expect(server.url).toBe("http://localhost:8010/_rehearsal/ready");
    expect(server.command).toMatch(/'frankenphp' 'localhost'$/);
  });

  it("passes the selected Laravel environment and rejects invalid names", () => {
    expect(laravelServer().env.REHEARSAL_MANAGED_APP_ENV).toBe("rehearsal");
    expect(laravelServer({ environment: "browser-tests" }).env.REHEARSAL_MANAGED_APP_ENV).toBe("browser-tests");
    expect(() => laravelServer({ environment: "../local" })).toThrow("Invalid Rehearsal environment name.");
  });

  it("stores explicit overrides privately instead of serializing their values into the webServer config", () => {
    const server = laravelServer({
      env: { APP_NAME: "Browser tests", STRIPE_KEY: undefined },
      database: { DB_PASSWORD: "test-db-password" },
      integrations: { REHEARSAL_INTEGRATION_SCOUT_KEY: "test-scout-key" },
    });
    const serialized = JSON.stringify({ webServer: server });
    for (const value of ["Browser tests", "test-db-password", "test-scout-key"]) {
      expect(serialized).not.toContain(value);
    }
    const file = server.env.REHEARSAL_MANAGED_ENV_FILE!;
    expect(statSync(file).mode & 0o777).toBe(0o600);
    expect(JSON.parse(readFileSync(file, "utf8"))).toEqual({
      managed: {
        REHEARSAL_MANAGED_DB_PASSWORD: "test-db-password",
        REHEARSAL_MANAGED_REHEARSAL_INTEGRATION_SCOUT_KEY: "test-scout-key",
      },
      env: { APP_NAME: "Browser tests" },
    });
    expect(laravelServer({ env: { STRIPE_KEY: undefined } }).env).not.toHaveProperty("REHEARSAL_MANAGED_ENV_FILE");
  });

  it("uses Typesense metadata under a custom integration name", () => {
    const origin = "http://127.0.0.1:8100";
    const config = { origin: "http://127.0.0.1:8108", collectionPrefix: "worker_", searchKey: "search-only" };
    const client = new Laravel(origin, {} as APIRequestContext, "cap", "attempt", origin, {
      search: { engine: "typesense", ...config },
    });
    expect(client.typesense()).toEqual(config);
  });

  it("preserves normal InstantSearch settings without an attempt and scopes all collection settings with one", () => {
    const options = {
      server: { apiKey: "normal", nodes: [{ host: "example.test" }], nearestNode: {}, cacheSearchResultsForSeconds: 10 },
      collectionSpecificSearchParameters: { users: { query_by: "name" } },
      collectionSpecificFacetByOptions: { users: { name: "sort_by:alpha:asc" } },
    };
    const ordinary = typesenseInstantSearch();
    expect(ordinary.configure(options)).toBe(options);
    expect(ordinary.indexName("users/sort/name:asc")).toBe("users/sort/name:asc");
    const isolated = typesenseInstantSearch({
      origin: "http://[::1]:18108", collectionPrefix: "slot_", searchKey: "search",
    });
    expect(isolated.indexName("users/sort/name:asc")).toBe("slot_users/sort/name:asc");
    expect(isolated.configure(options)).toEqual({
      server: {
        apiKey: "search", nodes: [{ host: "[::1]", port: 18108, protocol: "http" }], nearestNode: undefined,
        cacheSearchResultsForSeconds: 10,
      },
      collectionSpecificSearchParameters: { slot_users: { query_by: "name" } },
      collectionSpecificFacetByOptions: { slot_users: { name: "sort_by:alpha:asc" } },
    });
  });

  it("uses the root path for the configured Laravel origin", () => {
    const origin = "http://127.0.0.1:8100";
    expect(routePattern(origin, origin)).toEqual({ path: "/", method: undefined });
    expect(routePattern(origin + "/", origin)).toEqual({ path: "/", method: undefined });
    expect(() => routePattern("http://127.0.0.1:8101", origin)).toThrow("Laravel origin");
  });
});
