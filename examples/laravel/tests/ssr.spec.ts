import { expect, test } from "@sti3bas/rehearsal";

for (const name of ["Ada", "Grace", "Linus", "Margaret"]) {
  test("Nuxt SSR renders only " + name + "'s Laravel slot", async ({ laravel, page }) => {
    await laravel.factory("User", { name, email: "ssr@example.test" });
    const response = await page.goto("http://127.0.0.1:" + process.env.REHEARSAL_E2E_NUXT_PORT);
    expect(response?.ok()).toBe(true);
    expect(await response?.text()).toContain("<li>" + name + "</li>");
    await expect(page.locator("li")).toHaveText(["Baseline", name]);
  });
}
