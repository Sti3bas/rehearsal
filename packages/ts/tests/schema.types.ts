import { test } from "@sti3bas/rehearsal";
import { expectTypeOf } from "vitest";

declare module "@sti3bas/rehearsal" {
  interface RehearsalSchema {
    factories: {
      Listing: {
        attributes: { title?: string; price?: number };
        model: { id: number; title?: string; price?: number };
        states: "sold";
        relations: { seller: "User" };
      };
      User: {
        attributes: { name?: string };
        model: { id: number; name?: string };
        states: never;
        relations: {};
      };
    };
    operations: {
      "listing.sell": { input: { id: number }; output: { sold: boolean } };
      "stats.total": { input: Record<string, never>; output: number };
    };
    artisan: { "listings:sync": { force: boolean } };
    schedules: "expire";
    faultPaths: "/listings/**";
  }
}

test("generated types reach the Playwright fixture", async ({ laravel }) => {
  const listing = await laravel.create("Listing", { state: "sold", attrs: { title: "Chair" } });
  expectTypeOf(listing.id).toEqualTypeOf<number>();
  expectTypeOf(await laravel.call("listing.sell", { id: listing.id })).toEqualTypeOf<{ sold: boolean }>();
  expectTypeOf(await laravel.call("stats.total")).toEqualTypeOf<number>();
  await laravel.artisan("listings:sync", { force: true });
  await laravel.schedule.run(["expire"]);
  await laravel.route("/listings/**").fault({ status: 503 });
  await laravel.route({ method: "GET", url: "/listings/**" }).fault({ status: 503 });
  await laravel.route("/anything").respond({ status: 204 });
  const [user, listings] = await laravel.factory([
    { model: "User", attrs: { name: "Ada" } },
    { model: "Listing", count: 2, states: ["sold"], refs: { seller: 0 } },
  ]);
  expectTypeOf(user.name).toEqualTypeOf<string | undefined>();
  expectTypeOf(listings[0].id).toEqualTypeOf<number>();

  // @ts-expect-error unregistered model
  await laravel.factory("Missing");
  // @ts-expect-error unregistered state
  await laravel.create("Listing", { state: "inactive" });
  // @ts-expect-error unknown attribute
  await laravel.factory("User", { price: 10 });
  // @ts-expect-error incompatible attribute
  await laravel.factory("Listing", { price: "ten" });
  // @ts-expect-error unknown attribute in a batch
  await laravel.factory([{ model: "Listing", attrs: { title: "Chair", misspelt: true } }]);
  // @ts-expect-error unknown relation in a batch
  await laravel.factory([{ model: "Listing", refs: { buyer: 0 } }]);
  // @ts-expect-error unknown operation
  await laravel.call("listing.buy", { id: 1 });
  // @ts-expect-error activation requires an id
  await laravel.call("listing.sell");
  // @ts-expect-error an empty input does not accept extra properties
  await laravel.call("stats.total", { id: 1 });
  // @ts-expect-error unknown schedule
  await laravel.schedule.run("publish");
  // @ts-expect-error invalid Artisan args
  await laravel.artisan("listings:sync", { force: "yes" });
  // @ts-expect-error unregistered server fault path
  await laravel.route("/anything").fault({ status: 503 });
});
