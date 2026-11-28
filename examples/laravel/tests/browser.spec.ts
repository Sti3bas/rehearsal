import { expect, test } from "@sti3bas/rehearsal";

test("keeps the browser bound across a redirect", async ({ page }) => {
  const response = await page.goto("/probe/redirect");
  expect(response?.status()).toBe(200);
  expect(await response?.json()).toMatchObject({ users: ["Baseline"] });
});

test("claims a Laravel route mock for a percent-encoded browser path and query", async ({ laravel, page }) => {
  await laravel.route("/probe/café?color=grøn").respond({ body: "Unicode mock" });
  const response = await page.goto("/probe/caf%c3%a9?color=gr%C3%B8n");
  expect(await response?.text()).toBe("Unicode mock");
});

test("does not expose unlisted named connections to the application", async ({ page }) => {
  expect(await (await page.goto("/probe/outside-db"))?.json()).toEqual({ configured: false });
});

test("delivers server-sent events before the response finishes", async ({ page }) => {
  await page.goto("/");
  expect(await page.evaluate(() => new Promise((resolve) => {
    const events = new EventSource("/probe/events");
    const timer = setTimeout(() => {
      events.close();
      resolve("timed out");
    }, 2_000);
    events.addEventListener("probe", (event) => {
      clearTimeout(timer);
      events.close();
      resolve(event.data);
    });
  }))).toBe("ready");
});

test("serves public disk URLs from the bound slot", async ({ context }) => {
  const info = await context.request.get("/probe/public-file");
  expect(info.ok()).toBe(true);
  const file = await context.request.get((await info.json()).url);
  expect(file.ok()).toBe(true);
  expect(await file.text()).toBe("public probe");
});

test("serves signed temporary disk URLs and refuses unsigned access", async ({ context }) => {
  const info = await context.request.get("/probe/temporary-file");
  expect(info.ok()).toBe(true);
  const { url } = await info.json();
  const file = await context.request.get(url);
  expect(file.ok()).toBe(true);
  expect(await file.text()).toBe("private probe");
  expect((await context.request.get(url.split("?")[0])).status()).toBe(403);
});
