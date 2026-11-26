import { mkdir, readdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import type { Page } from "@playwright/test";
import { expect, test, type TypesenseClientConfig } from "@sti3bas/rehearsal";

function browserSearch(page: Page, config: TypesenseClientConfig, collection = config.collectionPrefix + "users") {
  return page.evaluate(async ({ origin, searchKey, collection, collectionPrefix }) => {
    const query = new URLSearchParams({ q: "*", query_by: "name", filter_by: `$${collectionPrefix}categories(name:="Baseline category")` });
    const response = await fetch(`${origin}/collections/${collection}/documents/search?${query}`, {
      headers: { "X-TYPESENSE-API-KEY": searchKey },
    });
    const hits = response.ok ? (await response.json()).hits.map((hit: { document: { name: string } }) => hit.document.name).sort() : [];
    return { status: response.status, hits };
  }, { ...config, collection });
}

test("sends mail over IPv6 loopback", async ({ laravel }) => {
  test.skip(process.env.REHEARSAL_E2E_SERVICE_HOST !== "[::1]", "IPv6-specific SMTP regression.");
  await laravel.operation("probe.mail", { title: "IPv6 SMTP" });
  await laravel.mailbox("receiver@example.test").expectReceived("IPv6 SMTP");
});

test("does not allow untrusted Typesense CORS origins", async ({ laravel, request }) => {
  test.skip(process.env.REHEARSAL_E2E_SCOUT !== "typesense", "Typesense-specific CORS configuration.");
  const response = await request.fetch(laravel.typesense().origin + "/collections", {
    method: "OPTIONS",
    headers: {
      Origin: "https://untrusted.example",
      "Access-Control-Request-Method": "GET",
      "Access-Control-Request-Headers": "X-TYPESENSE-API-KEY",
    },
  });
  expect(response.headers()["access-control-allow-origin"]).toBeUndefined();
});

for (const name of ["Ada", "Grace", "Linus", "Margaret"]) {
  test("isolates Scout and Mailpit for " + name, async ({ laravel, page }, info) => {
    const title = "Probe " + name;
    const inbox = laravel.mailbox("receiver@example.test");
    expect(await laravel.operation("probe.search")).toEqual([]);
    expect(await inbox.all()).toEqual([]);
    await laravel.factory("User", { name: title, email: "probe@example.test" });
    await laravel.operation("probe.mail", { title });
    const barrier = resolve(info.project.outputDir, "service-barrier");
    await mkdir(barrier, { recursive: true });
    await writeFile(resolve(barrier, String(info.parallelIndex)), title);
    await expect.poll(async () => (await readdir(barrier)).length).toBe(4);
    await expect.poll(() => laravel.operation("probe.search")).toEqual([title]);
    await inbox.expectReceived(title);
    expect(await inbox.all()).toEqual([{ id: expect.any(String), subject: title }]);
    expect(await inbox.link(/verify address/i, { subject: /^Probe / })).toBe("https://example.test/verify?a=1&b=2");
    expect(await laravel.mailbox("other@example.test").all()).toEqual([]);
    if (process.env.REHEARSAL_E2E_SCOUT === "typesense") {
      const typesense = laravel.typesense();
      await page.goto("/");
      await expect(browserSearch(page, typesense)).resolves.toEqual({ status: 200, hits: ["Baseline", title] });
      const nextSlot = (info.parallelIndex + 1) % 4 + 1;
      const otherCollection = typesense.collectionPrefix.replace(/_s\d+_$/, `_s${nextSlot}_`) + "users";
      await expect(browserSearch(page, typesense, otherCollection)).resolves.toEqual({ status: 401, hits: [] });
      const writeStatus = await page.evaluate(async ({ origin, collectionPrefix, searchKey }) => {
        const response = await fetch(`${origin}/collections/${collectionPrefix}users/documents`, {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-TYPESENSE-API-KEY": searchKey },
          body: JSON.stringify({ id: "forbidden", name: "Forbidden" }),
        });
        return response.status;
      }, typesense);
      expect(writeStatus).toBe(401);
    }
  });
}

test.describe("slot reuse", () => {
  test.describe.configure({ mode: "serial" });
  let previousTypesense: TypesenseClientConfig | undefined;
  test("fills search and mail before reset", async ({ laravel, page }) => {
    await laravel.factory("User", { name: "Probe stale" });
    await laravel.operation("probe.mail", { title: "Probe stale" });
    await expect.poll(() => laravel.operation("probe.search")).toEqual(["Probe stale"]);
    await laravel.mailbox("receiver@example.test").expectReceived("Probe stale");
    if (process.env.REHEARSAL_E2E_SCOUT === "typesense") {
      previousTypesense = laravel.typesense();
      await page.goto("/");
      await expect(browserSearch(page, previousTypesense)).resolves.toEqual({ status: 200, hits: ["Baseline", "Probe stale"] });
    }
  });

  test("empties search and mail between attempts", async ({ laravel, page }) => {
    expect(await laravel.operation("probe.search")).toEqual([]);
    expect(await laravel.mailbox("receiver@example.test").all()).toEqual([]);
    if (previousTypesense) {
      const typesense = laravel.typesense();
      expect(typesense.collectionPrefix).toBe(previousTypesense.collectionPrefix);
      await page.goto("/");
      await expect(browserSearch(page, typesense)).resolves.toEqual({ status: 200, hits: ["Baseline"] });
      await expect(browserSearch(page, previousTypesense)).resolves.toEqual({ status: 401, hits: [] });
    }
  });
});

test("applies Meilisearch settings keyed by index name", async ({ laravel }) => {
  test.skip(process.env.REHEARSAL_E2E_SCOUT !== "meilisearch", "Meilisearch-specific index settings.");
  await laravel.factory("User", { name: "Probe matching" });
  await laravel.factory("User", { name: "Probe other" });
  await expect.poll(() => laravel.operation("probe.search")).toEqual(expect.arrayContaining(["Probe matching", "Probe other"]));
  await expect(laravel.operation("probe.search", { name: "Probe matching" })).resolves.toEqual(["Probe matching"]);
});

test("keeps class-keyed settings alongside named Meilisearch settings", async ({ laravel }) => {
  test.skip(process.env.REHEARSAL_E2E_SCOUT !== "meilisearch", "Meilisearch-specific index settings.");
  await laravel.factory("User", { name: "Probe first" });
  await laravel.factory("User", { name: "Probe second" });
  await expect.poll(() => laravel.operation("probe.search", { sort: true })).toEqual(["Probe second", "Probe first"]);
});

test("does not index rolled-back factory rows", async ({ laravel }) => {
  test.skip(process.env.REHEARSAL_E2E_SCOUT === "database", "Database search has no remote documents.");
  await expect(laravel.factory([
    { model: "User", attrs: { name: "Probe rolled back", email: "duplicate@example.test" } },
    { model: "User", attrs: { name: "Probe duplicate", email: "duplicate@example.test" } },
  ])).rejects.toThrow("HTTP 500");
  await laravel.factory("User", { id: 1024, name: "Probe committed", email: "committed@example.test" });
  await expect.poll(() => laravel.operation("probe.search")).toEqual(["Probe committed"]);
});

test("filters soft-deleted models in Meilisearch", async ({ laravel }) => {
  test.skip(process.env.REHEARSAL_E2E_SCOUT !== "meilisearch", "Meilisearch-specific soft-delete filter.");
  const user = await laravel.factory("User", { name: "Probe soft deleted" });
  await expect.poll(() => laravel.operation("probe.search")).toEqual(["Probe soft deleted"]);
  await laravel.operation("probe.delete-user", { id: user.id });
  await expect.poll(() => laravel.operation("probe.search")).toEqual([]);
});
