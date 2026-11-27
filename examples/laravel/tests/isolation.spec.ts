import { exec } from "node:child_process";
import { mkdir, mkdtemp, readdir, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { promisify } from "node:util";
import { expect, laravelServer, test } from "@sti3bas/rehearsal";

const collision = "same-address@example.test";

test("uses the managed rehearsal environment and forwards Laravel env values", async ({ page }) => {
  const response = await page.request.get("/probe/environment");
  expect(await response.json()).toEqual({ environment: "rehearsal", name: "Managed Rehearsal" });
});

test("preserves private overrides when the same webServer config launches twice", async () => {
  const root = await mkdtemp(join(tmpdir(), "rehearsal-relaunch-"));
  try {
    await writeFile(join(root, ".env.rehearsal"), "");
    await writeFile(join(root, "artisan"), "<?php if ($argv[1] === 'serve' && getenv('APP_NAME') !== 'Repeated launch') exit(1);");
    const { command, cwd, env } = laravelServer({ cwd: root, env: { APP_NAME: "Repeated launch" } });
    const launch = () => promisify(exec)(command, { cwd, env: { ...process.env, ...env } });
    await launch();
    await launch();
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("creates discovered models and rejects unregistered factory states", async ({ laravel }) => {
  const category = await laravel.factory("Category", { name: "Collectibles" });
  expect(category.name).toBe("Collectibles");
  await expect(laravel.factory("Category", {}, { states: ["count"] })).rejects.toThrow("HTTP 422");
  await expect(laravel.factory("App\\Models\\Category").create()).rejects.toThrow("HTTP 422");
});

test("resolves configured belongs-to references in a factory batch", async ({ laravel }) => {
  const [category, users] = await laravel.factory([
    { model: "Category", attrs: { name: "Collectibles" } },
    { model: "User", count: 2, refs: { category: 0 } },
  ]);
  expect(users.map((user) => user.category_id)).toEqual([category.id, category.id]);
  await expect(laravel.factory([
    { model: "User", refs: { category: 1 } },
    { model: "Category" },
  ])).rejects.toThrow("HTTP 422");
});

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
    const rateLimited = async () => (await page.request.get("/probe/rate-limit")).status();
    expect(await rateLimited()).toBe(204);
    expect(await rateLimited()).toBe(204);
    expect(await rateLimited()).toBe(429);

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
