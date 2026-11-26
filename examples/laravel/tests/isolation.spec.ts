import { mkdir, readdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { expect, test } from "@sti3bas/rehearsal";

const collision = "same-address@example.test";

test("logs additional browser contexts into independent sessions in the same attempt", async ({ laravel, page, browser, baseURL }) => {
  const original = await laravel.factory("User", { name: "Original" });
  const actor = await laravel.factory("User", { name: "Actor" });
  await laravel.login({ id: Number(original.id) });

  const secondary = await browser.newContext({ baseURL });
  try {
    await laravel.login({ id: Number(actor.id) }, secondary);
    expect(await (await secondary.request.get("/state")).json()).toMatchObject({ user: "Actor" });
    expect(await (await page.request.get("/state")).json()).toMatchObject({ user: "Original" });
  } finally {
    await secondary.close();
  }
});

for (const name of ["Ada", "Grace", "Linus", "Margaret"]) {
  test("isolates " + name + " with identical IDs and resource keys", async ({ laravel, page }, info) => {
    const base = await page.goto("/state");
    expect(await base?.json()).toMatchObject({ users: ["Baseline"], user: null, cache: null, disk: null });
    const user = await laravel.factory("User", { name, email: collision });
    expect(user.id).toBe(2);
    await laravel.login({ id: Number(user.id) });
    await laravel.operation("probe.write", { value: name });
    expect(await laravel.queue.drain()).toMatchObject({ pending: 0, failed: 0, removed: 1 });

    const peers = Number(process.env.REHEARSAL_E2E_WORKERS ?? 4);
    if (peers === 4) {
      const barrier = resolve(info.project.outputDir, "isolation-barrier");
      await mkdir(barrier, { recursive: true });
      await writeFile(resolve(barrier, String(info.parallelIndex)), name);
      await expect.poll(async () => (await readdir(barrier)).length, { timeout: 15_000 }).toBe(4);
    }
    const final = await page.goto("/state");
    expect(await final?.json()).toMatchObject({ users: ["Baseline", name], user: name, cache: name, disk: name });
    await laravel.travel("2034-01-02T12:00:00.000Z");
    expect(await page.evaluate(() => new Date().toISOString())).toBe("2034-01-02T12:00:00.000Z");
    expect(await (await page.goto("/state"))?.json()).toMatchObject({ time: "2034-01-02" });
    expect(await laravel.queue.failed()).toEqual([]);
    await expect(laravel.schedule.run("not-allowlisted")).rejects.toThrow("HTTP 422");
    expect(await laravel.schedule.run("probe", { drain: {} })).toMatchObject({
      results: [{ name: "probe", code: 0 }],
      drain: { processed: 1, failed: 0 },
    });
    await laravel.schedule.run("probe");
    await laravel.queue.drainUntil(async () => {
      expect(await (await page.request.get("/state")).json()).toMatchObject({ cache: "scheduled" });
    });
    await laravel.schedule.run("fail-probe");
    await expect(laravel.queue.drainUntil(() => {})).rejects.toThrow("WriteProbe");
    expect(await laravel.queue.failed()).toEqual([
      expect.objectContaining({ job: "App\\Jobs\\WriteProbe", exception: "RuntimeException" }),
    ]);
  });
}
