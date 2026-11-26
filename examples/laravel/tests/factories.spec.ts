import { expect, test } from "@sti3bas/rehearsal";

test("batches states and runs only registered Artisan operations without opening a browser", async ({ laravel }) => {
  const rows = await laravel.factory([
    { model: "User", attrs: { name: "Ada" } },
    { model: "User", states: ["staff"], count: 2 },
  ]);
  expect(rows).toMatchObject([{ id: 2, name: "Ada" }, [{ id: 3, name: "Staff" }, { id: 4, name: "Staff" }]]);
  expect(await laravel.artisan("about")).toEqual({ code: 0 });
  await expect(laravel.artisan("migrate:fresh")).rejects.toThrow("HTTP 404");
});

test("passes exact strings to factory attributes", async ({ laravel }) => {
  expect(await laravel.factory([
    { model: "User", attrs: { name: "" } },
    { model: "User", attrs: { name: " Ada " } },
  ])).toMatchObject([{ name: "" }, { name: " Ada " }]);
});
