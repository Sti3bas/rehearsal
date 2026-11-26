import { expect, test } from "@sti3bas/rehearsal";

for (const name of ["Ada", "Grace", "Linus", "Margaret"]) {
  test("authenticates Sanctum from Nuxt and renders " + name + " on the server", async ({ laravel, page, baseURL, request }) => {
    const nuxt = "http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT;
    const email = "sanctum@example.test";
    const password = "example-password";
    await laravel.factory("User", { name, email, password });
    const corsHeaders = {
      Origin: nuxt,
      "Access-Control-Request-Method": "POST",
      "Access-Control-Request-Headers": "content-type,x-xsrf-token",
    };
    const preflight = await request.fetch(baseURL + "/login", { method: "OPTIONS", headers: corsHeaders });
    expect(preflight.status()).toBe(204);
    expect(preflight.headers()["access-control-allow-origin"]).toBe(nuxt);
    expect((await request.fetch(baseURL + "/login", { method: "OPTIONS" })).status()).toBe(403);
    expect((await request.fetch(baseURL + "/state", { method: "OPTIONS", headers: corsHeaders })).status()).toBe(403);
    await page.goto(nuxt);
    const auth = await page.evaluate(async ({ api, email, password }) => {
      const credentials = "include" as const;
      const csrf = await fetch(api + "/sanctum/csrf-cookie", { credentials });
      const token = decodeURIComponent(document.cookie.split("; ").find((cookie) => cookie.startsWith("XSRF-TOKEN="))!.slice(11));
      const session = await fetch(api + "/login", {
        method: "POST", credentials,
        headers: { "Content-Type": "application/json", "X-XSRF-TOKEN": token, Accept: "application/json" },
        body: JSON.stringify({ email, password }),
      });
      const user = await fetch(api + "/api/user", { credentials, headers: { Accept: "application/json" } });
      return { csrf: csrf.status, login: session.status, user: await user.json() };
    }, { api: baseURL!, email, password });
    expect(auth).toEqual({ csrf: 204, login: 204, user: { name } });
    const response = await page.goto(nuxt + "/?authenticated=1");
    expect(await response?.text()).toContain('data-testid="authenticated-user">' + name);
    await expect(page.getByTestId("authenticated-user")).toHaveText(name);
  });
}

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
    await laravel.route("/probe/outside-db").respond({});
    expect(await page.evaluate(async () => {
      const response = await fetch("/probe/outside-db");
      return { status: response.status, body: await response.text() };
    })).toEqual({ status: 200, body: "" });
    await laravel.route("/probe/outside-db").respond({ networkError: true, once: true });
    await expect(page.evaluate(() => fetch("/probe/outside-db"))).rejects.toThrow();
    await laravel.route("/probe/redirect").fault({ status: 503, json: { unavailable: {} }, once: true });
    const fault = await page.request.get(baseURL + "/probe/redirect");
    expect(fault.status()).toBe(503);
    expect(await fault.json()).toEqual({ unavailable: {} });
    await laravel.route("/probe/outside-db").respond({ json: { empty: {}, numbers: [] } });
    expect(await page.evaluate(async () => (await fetch("/probe/outside-db")).json())).toEqual({ empty: {}, numbers: [] });
    expect((await page.request.get(baseURL + "/probe/redirect", { maxRedirects: 0 })).status()).toBe(302);
    await laravel.route("/state").respond({ json: { users: [name + " SSR only"] }, delay: 10, once: true });
    const response = await page.goto("http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT);
    expect(await response?.text()).toContain("<li>" + name + " SSR only</li>");
    await page.goto("/");
    expect(await fetchState("/state")).toEqual({ status: 200, users: [name] });
  });
}

test.describe("route mocks on a reused slot", () => {
  test.describe.configure({ mode: "serial" });
  test("stubs a Laravel route", async ({ laravel, page }) => {
    await laravel.route("/state").respond({ json: { users: ["stale"] } });
    await page.goto("http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT);
    await expect(page.locator("li")).toHaveText(["stale"]);
  });

  test("relays to real Laravel in the next attempt", async ({ page }) => {
    await page.goto("http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT);
    await expect(page.locator("li")).toHaveText(["Baseline"]);
  });
});
