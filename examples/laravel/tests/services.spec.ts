import { mkdir, readdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { expect, test } from "@sti3bas/rehearsal";

test("sends mail over IPv6 loopback", async ({ laravel }) => {
  test.skip(process.env.REHEARSAL_E2E_SERVICE_HOST !== "[::1]", "IPv6-specific SMTP regression.");
  await laravel.operation("probe.mail", { title: "IPv6 SMTP" });
  await expect.poll(() => laravel.operation("probe.inbox")).toEqual(["IPv6 SMTP"]);
});

for (const name of ["Ada", "Grace", "Linus", "Margaret"]) {
  test("isolates Scout and Mailpit for " + name, async ({ laravel }, info) => {
    const title = "Probe " + name;
    expect(await laravel.operation("probe.search")).toEqual([]);
    expect(await laravel.operation("probe.inbox")).toEqual([]);
    await laravel.factory("User", { name: title, email: "probe@example.test" });
    await laravel.operation("probe.mail", { title });
    const barrier = resolve(info.project.outputDir, "service-barrier");
    await mkdir(barrier, { recursive: true });
    await writeFile(resolve(barrier, String(info.parallelIndex)), title);
    await expect.poll(async () => (await readdir(barrier)).length).toBe(4);
    await expect.poll(() => laravel.operation("probe.search")).toEqual([title]);
    await expect.poll(() => laravel.operation("probe.inbox")).toEqual([title]);
  });
}

test.describe("slot reuse", () => {
  test.describe.configure({ mode: "serial" });
  test("fills search and mail before reset", async ({ laravel }) => {
    await laravel.factory("User", { name: "Probe stale" });
    await laravel.operation("probe.mail", { title: "Probe stale" });
    await expect.poll(() => laravel.operation("probe.search")).toEqual(["Probe stale"]);
    await expect.poll(() => laravel.operation("probe.inbox")).toEqual(["Probe stale"]);
  });

  test("empties search and mail between attempts", async ({ laravel }) => {
    expect(await laravel.operation("probe.search")).toEqual([]);
    expect(await laravel.operation("probe.inbox")).toEqual([]);
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
