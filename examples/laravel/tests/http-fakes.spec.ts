import { expect, test } from "@sti3bas/rehearsal";

const origin = "https://api.openai.com/v1/moderations*";

for (const name of ["Ada", "Grace", "Linus", "Margaret"]) {
  test("isolates outbound moderation responses for " + name, async ({ laravel, context }) => {
    expect((await context.request.get("/probe/moderation")).status()).toBe(503);
    await laravel.http.fake({
      [origin]: {
        method: "POST",
        responses: [
          { networkError: true },
          { status: 202, json: { results: [{ id: name, flagged: false, categories: {} }] } },
        ],
      },
    });
    expect((await context.request.get("/probe/moderation")).status()).toBe(503);
    const result = await context.request.get("/probe/moderation");
    expect(result.status()).toBe(202);
    expect(await result.json()).toEqual({ results: [{ id: name, flagged: false, categories: {} }] });
    expect((await context.request.get("/probe/moderation")).status()).toBe(202);
    await laravel.http.fake({});
    expect((await context.request.get("/probe/moderation")).status()).toBe(503);
  });
}

test.describe("outbound fakes on a reused slot", () => {
  test.describe.configure({ mode: "serial" });
  test("sets a fake", async ({ laravel, context }) => {
    await laravel.http.fake({ [origin]: { json: { id: "stale" } } });
    expect(await (await context.request.get("/probe/moderation")).json()).toEqual({ id: "stale" });
  });
  test("does not carry the fake into the next attempt", async ({ context }) => {
    expect((await context.request.get("/probe/moderation")).status()).toBe(503);
  });
});
