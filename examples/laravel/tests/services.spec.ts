import { mkdir, readdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { expect, test } from "@sti3bas/rehearsal";

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

test("does not index rolled-back factory rows", async ({ laravel }) => {
  test.skip(process.env.REHEARSAL_E2E_SCOUT === "database", "Database search has no remote documents.");
  await expect(laravel.factory([
    { model: "User", attrs: { name: "Probe rolled back", email: "duplicate@example.test" } },
    { model: "User", attrs: { name: "Probe duplicate", email: "duplicate@example.test" } },
  ])).rejects.toThrow("HTTP 500");
  await laravel.factory("User", { id: 1024, name: "Probe committed", email: "committed@example.test" });
  await expect.poll(() => laravel.operation("probe.search")).toEqual(["Probe committed"]);
});
