import { expect, test } from "@sti3bas/rehearsal";

for (const name of ["Ada", "Grace", "Linus", "Margaret"]) {
  test("Nuxt SSR renders only " + name + "'s Laravel slot", async ({ laravel, page }) => {
    await laravel.factory("User", { name, email: "ssr@example.test" });
    const response = await page.goto("http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT);
    expect(response?.ok()).toBe(true);
    expect(await response?.text()).toContain("<li>" + name + "</li>");
    await expect(page.locator("li")).toHaveText(["Baseline", name]);
  });

  test("isolates shared browser and SSR route mocks for " + name, async ({ laravel, page, baseURL }) => {
    await page.goto("/");
    const state = (path: string) => ({ url: path, method: "get" as const });
    await laravel.route(state, "/state").respond({ json: { users: [name] } });
    await laravel.route("/state?preview=*").respond({ status: 202, json: { users: ["preview"] }, once: true });
    const fetchState = (path: string) => page.evaluate(async (url) => {
      const response = await fetch(url);
      return { status: response.status, users: (await response.json()).users };
    }, path);
    expect(await fetchState("/state?preview=one")).toEqual({ status: 202, users: ["preview"] });
    expect(await fetchState("/state?preview=one")).toEqual({ status: 200, users: [name] });
    await laravel.route("/probe/outside-db").respond({ networkError: true, once: true });
    await expect(page.evaluate(() => fetch("/probe/outside-db"))).rejects.toThrow();
    await laravel.route("/probe/redirect").fault({ status: 503, json: { unavailable: true }, once: true });
    expect((await page.request.get(baseURL + "/probe/redirect")).status()).toBe(503);
    expect((await page.request.get(baseURL + "/probe/redirect", { maxRedirects: 0 })).status()).toBe(302);
    await laravel.route("/state").respond({ json: { users: [name + " SSR only"] }, delay: 10, once: true });
    const response = await page.goto("http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT);
    expect(await response?.text()).toContain("<li>" + name + " SSR only</li>");
    await page.goto("/");
    expect(await fetchState("/state")).toEqual({ status: 200, users: [name] });
  });
}
