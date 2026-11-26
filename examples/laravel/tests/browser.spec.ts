import { expect, test } from "@sti3bas/rehearsal";

test("keeps the browser bound across a redirect", async ({ page }) => {
  const response = await page.goto("/probe/redirect");
  expect(response?.status()).toBe(200);
  expect(await response?.json()).toMatchObject({ users: ["Baseline"] });
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
