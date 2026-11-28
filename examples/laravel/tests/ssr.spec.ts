import { mergeTests } from "@playwright/test";
import { expect, nuxtTest, passthrough, test } from "@sti3bas/rehearsal";

const nuxt = mergeTests(test, nuxtTest);
nuxt.use({ nuxtURL: "http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT });

nuxt("matches Nuxt fake root URLs without a trailing slash", async ({ nuxt, page, baseURL }) => {
  await nuxt.fake({ [`GET ${baseURL}`]: { body: "Fake root" } });
  expect(await (await page.request.get(baseURL!)).text()).toBe("Rehearsal");
  expect(await (await page.goto(baseURL!))?.text()).toBe("Fake root");
  await nuxt.fake({ [`GET ${baseURL}*`]: { body: "Fake wildcard" } });
  expect(await (await page.goto(baseURL! + "/state"))?.text()).toBe("Fake wildcard");
  await nuxt.fake({ "GET https://*.example.test": { body: "Fake wildcard host" } });
  expect(await (await page.goto("https://shop.example.test/"))?.text()).toBe("Fake wildcard host");
});

nuxt("leaves Nuxt redirects to the browser and fakes direct navigations", async ({ nuxt, page, baseURL, nuxtURL }) => {
  await nuxt.fake({ [`GET ${baseURL}/state`]: { json: { users: ["Redirected"] } } });
  const response = await page.goto(nuxtURL + "/api/fake-redirect", { waitUntil: "commit" });
  expect(page.url()).toBe(nuxtURL + "/api/state");
  expect(response!.request().redirectedFrom()?.url()).toBe(nuxtURL + "/api/fake-redirect");
  expect((await response!.json()).global.users).toEqual(["Baseline"]);
  expect((await (await page.goto(nuxtURL + "/api/state"))!.json()).global.users).toEqual(["Redirected"]);
});

nuxt("forwards an SSR replacement body to the real destination on a fake miss", async ({ nuxt, page, baseURL, nuxtURL }) => {
  await nuxt.fake({ [`GET ${baseURL}/state`]: { json: { users: ["Unrelated"] } } });
  expect(await (await page.goto(nuxtURL + "/api/fake-echo"))?.json()).toEqual({ body: "replacement" });
});

for (const name of ["Ada", "Grace"]) {
  nuxt("shares runtime Nuxt fake handlers between SSR and the browser for " + name, async ({ nuxt, page, baseURL, nuxtURL }) => {
    let hits = 0;
    await nuxt.fake({
      [`GET ${baseURL}/state`]: (request) => {
        if (new URL(request.url).searchParams.has("real")) return passthrough;
        return {
          headers: { "access-control-allow-origin": "*" },
          json: { users: [name], hit: ++hits },
        };
      },
    });
    const response = await page.goto(nuxtURL + "/api/state");
    expect(response?.ok()).toBe(true);
    const { global, imported, redirect, proxyHeader } = await response!.json();
    expect([global.hit, imported.hit].sort()).toEqual([1, 2]);
    expect(global.users).toEqual([name]);
    expect(imported.users).toEqual([name]);
    expect(redirect).toBe(302);
    expect(proxyHeader).toBeNull();
    const state = () => page.evaluate(async (url) => (await fetch(url)).json(), baseURL + "/state");
    expect(await state()).toEqual({ users: [name], hit: 3 });
    await nuxt.fake({
      [`GET ${baseURL}/state`]: [
        { headers: { "access-control-allow-origin": "*" }, json: { users: ["first"] } },
        { headers: { "access-control-allow-origin": "*" }, json: { users: ["second"] } },
      ],
    });
    expect(await state()).toEqual({ users: ["first"] });
    const sequence = await (await page.goto(nuxtURL + "/api/state"))!.json();
    expect(sequence.global.users).toEqual(["second"]);
    expect(sequence.imported.users).toEqual(["second"]);
    await nuxt.fake({ [`GET ${baseURL}/state`]: passthrough });
    expect((await (await page.goto(nuxtURL + "/api/state"))!.json()).global.users).toEqual(["Baseline"]);
  });
}

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
      const login = (email: string, password: string) => fetch(api + "/login", {
        method: "POST", credentials,
        headers: { "Content-Type": "application/json", "X-XSRF-TOKEN": token, Accept: "application/json" },
        body: JSON.stringify({ email, password }),
      });
      const rejected = await login("baseline@example.test", "wrong-password");
      const session = await login(email, password);
      const user = await fetch(api + "/api/user", { credentials, headers: { Accept: "application/json" } });
      return { csrf: csrf.status, rejected: rejected.status, login: session.status, user: await user.json() };
    }, { api: baseURL!, email, password });
    expect(auth).toEqual({ csrf: 204, rejected: 401, login: 204, user: { name } });
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

  test("Nitro automatically binds global and imported fetch to " + name + "'s slot", async ({ laravel, page, request }) => {
    await laravel.factory("User", { name, email: "nitro@example.test" });
    const url = "http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT + "/api/state";
    const response = await page.request.get(url);
    expect(response.ok()).toBe(true);
    const { global, imported, redirect } = await response.json();
    expect(global.users).toEqual(["Baseline", name]);
    expect(imported.users).toEqual(global.users);
    expect(redirect).toBe(302);
    expect((await request.get(url)).ok()).toBe(false);
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
    await laravel.fake("GET /probe/redirect", { status: 503, json: { unavailable: {} }, once: true });
    const fake = await page.request.get(baseURL + "/probe/redirect");
    expect(fake.status()).toBe(503);
    expect(await fake.json()).toEqual({ unavailable: {} });
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

test("applies the app's CORS policy to a Laravel route fake", async ({ laravel, page, baseURL }) => {
  const origin = "http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT;
  await laravel.fake({ url: "/login", method: "post" }, {
    status: 422,
    json: { message: "Injected validation error" },
  });
  await page.goto(origin);
  const result = await page.evaluate(async (url) => {
    const response = await fetch(url, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: "{}",
    });
    return { status: response.status, body: await response.json() };
  }, baseURL + "/login");
  expect(result).toEqual({ status: 422, body: { message: "Injected validation error" } });

  const denied = await page.request.post(baseURL + "/login", {
    headers: { Origin: "http://127.0.0.1:1" },
  });
  expect(denied.status()).toBe(422);
  expect(denied.headers()["access-control-allow-origin"]).not.toBe("http://127.0.0.1:1");
});

test("returns a headers-only fake once on the real server", async ({ laravel, page, baseURL }) => {
  await laravel.fake("GET /probe/redirect", { headers: { "X-Probe-Fake": "once" }, once: true });
  const first = await page.request.get(baseURL + "/probe/redirect", { maxRedirects: 0 });
  expect(first.headers()["x-probe-fake"]).toBe("once");
  expect(first.status()).toBe(200);
  expect(await first.text()).toBe("");

  const second = await page.request.get(baseURL + "/probe/redirect", { maxRedirects: 0 });
  expect(second.status()).toBe(302);
  expect(second.headers()["x-probe-fake"]).toBeUndefined();
});

test("shares finite mock claims and paused requests between browser and SSR", async ({ laravel, page }) => {
  const frontend = "http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT;
  const browserUsers = () => page.evaluate(async () => (await (await fetch("/state")).json()).users);
  await page.goto("/");
  await laravel.route("/state").respond({ json: { users: ["twice"] }, times: 2 });
  expect(await browserUsers()).toEqual(["twice"]);
  expect(await (await page.request.get(frontend)).text()).toContain("<li>twice</li>");
  expect(await browserUsers()).toEqual(["Baseline"]);

  const gate = await laravel.route("/state").pause({ json: { users: ["released"] } });
  let settled = false;
  const pending = page.request.get(frontend).finally(() => { settled = true; });
  try {
    await gate.waitForRequest();
    await laravel.clearRouteMocks();
    expect(await browserUsers()).toEqual(["Baseline"]);
    expect(settled).toBe(false);
  } finally {
    await gate.release();
  }
  expect(await (await pending).text()).toContain("<li>released</li>");

  const passthrough = await laravel.route("/state").pause();
  const browser = browserUsers();
  try {
    await passthrough.waitForRequest();
  } finally {
    await passthrough.release();
  }
  expect(await browser).toEqual(["Baseline"]);
});

test("clears finite Laravel route fakes mid-attempt", async ({ laravel, page, baseURL }) => {
  const redirect = () => page.request.get(baseURL + "/probe/redirect", { maxRedirects: 0 });
  await laravel.fake("GET /probe/redirect", { status: 503, times: 2 });
  expect((await redirect()).status()).toBe(503);
  expect((await redirect()).status()).toBe(503);
  expect((await redirect()).status()).toBe(302);
  await laravel.fake("GET /probe/redirect", { status: 503 });
  await laravel.clearFakes();
  expect((await redirect()).status()).toBe(302);
});

test("shares a Laravel fake sequence across browser and SSR requests", async ({ laravel, page }) => {
  const frontend = "http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT;
  const browserUsers = () => page.evaluate(async () => (await (await fetch("/state")).json()).users);
  await page.goto("/");
  await expect(laravel.fake("POST /state", { status: 201 })).rejects.toThrow(/422/);
  await laravel.fake({ method: "get", url: "/state" }, [
    { json: { users: ["First"] }, times: 2 },
    { json: { users: ["Last"] } },
  ]);
  expect(await browserUsers()).toEqual(["First"]);
  expect(await (await page.request.get(frontend)).text()).toContain("<li>First</li>");
  expect(await browserUsers()).toEqual(["Last"]);
  expect(await (await page.request.get(frontend)).text()).toContain("<li>Last</li>");
  await laravel.clearFakes();
  expect(await browserUsers()).toEqual(["Baseline"]);
});

test("runs Laravel route fake callbacks in the Playwright test", async ({ laravel, page }) => {
  const frontend = "http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT;
  const hits: string[] = [];
  await page.goto("/");
  await laravel.fake("GET /state", (request) => {
    const name = new URL(request.url).searchParams.getAll("name").join(" ") || "SSR";
    hits.push(name);
    return { json: { users: [name] } };
  });
  expect(await page.evaluate(async () => (await (await fetch("/state/?name=Browser&name=Repeated")).json()).users)).toEqual(["Browser Repeated"]);
  expect(await (await page.request.get(frontend)).text()).toContain("<li>SSR</li>");
  expect(hits).toEqual(["Browser Repeated", "SSR"]);
});

test("reads multipart fields and files in Laravel fake callbacks", async ({ laravel, page }) => {
  await page.goto("/");
  await laravel.fake("POST /login", async (request) => {
    const form = await request.formData();
    return { json: { value: form.get("fields[name]"), tags: form.getAll("tags[]"), file: await (form.get("file") as File).text() } };
  });
  const multipart = new FormData();
  multipart.append("fields[name]", "sample");
  multipart.append("tags[]", "oak");
  multipart.append("tags[]", "pine");
  multipart.append("file", new Blob(["file text"], { type: "text/plain" }), "sample.txt");
  expect(await (await page.request.post("/login", {
    multipart,
  })).json()).toEqual({ value: "sample", tags: ["oak", "pine"], file: "file text" });
});

test("reads multipart uploads larger than the original callback bridge limit", async ({ laravel, page }) => {
  await laravel.fake("POST /login", async (request) => {
    const file = (await request.formData()).get("file") as File;
    return { json: { size: file.size } };
  });
  const size = 1_700_000;
  expect(await (await page.request.post("/login", {
    multipart: { file: { name: "large.txt", mimeType: "text/plain", buffer: Buffer.alloc(size, 97) } },
  })).json()).toEqual({ size });
});

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
