import { expect, test } from "@sti3bas/rehearsal";

test.skip(process.env.REHEARSAL_E2E_SCOUT !== "typesense" || !process.env.REHEARSAL_E2E_NUXT_PORT,
  "Requires Nuxt and real Typesense.");

for (const name of ["Ada", "Grace", "Linus", "Margaret"]) {
  test("scopes the InstantSearch adapter to " + name + "'s slot in SSR and the browser", async ({ laravel, page, request, baseURL }) => {
    await laravel.factory("User", { name, email: "typesense-nuxt@example.test" });
    const url = "http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT + "/?search=1";
    const metadata = await page.request.get(baseURL + "/_rehearsal/typesense");
    const credentials = laravel.typesense();
    expect(metadata.ok()).toBe(true);
    expect(metadata.headers()["cache-control"]).toContain("no-store");
    expect(await metadata.json()).toEqual({ engine: "typesense", ...credentials });
    expect((await request.get(baseURL + "/_rehearsal/typesense")).status()).toBe(403);
    const response = await page.goto(url);
    expect(response?.ok()).toBe(true);
    expect(response?.headers()["cache-control"]).toContain("no-store");
    const names = ["Baseline", name].sort().join(", ");
    expect(await response?.text()).toContain('data-testid="typesense-ssr">' + names);
    const browserSearch = page.waitForRequest((outbound) => outbound.url().startsWith(credentials.origin + "/multi_search"));
    await page.getByTestId("typesense-search").click();
    const outbound = await browserSearch;
    const searchKey = outbound.headers()["x-typesense-api-key"] ??
      new URL(outbound.url()).searchParams.get("x-typesense-api-key");
    expect(searchKey === credentials.searchKey).toBe(true);
    expect(outbound.postDataJSON().searches[0].collection).toBe(credentials.collectionPrefix + "users");
    await expect(page.getByTestId("typesense-browser")).toHaveText(names);
  });
}
