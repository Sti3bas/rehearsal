import { test, type FactoryModelRef, type ModelResult } from "@sti3bas/rehearsal";
import { expectTypeOf } from "vitest";

declare module "@sti3bas/rehearsal" {
  interface RehearsalSchema {
    factories: {
      Listing: {
        attributes: { title?: string; price?: number; display_title?: string };
        model: { id: number; title?: string; price?: number; summary?: string };
        modelColumns: "id" | "price" | "title";
        states: {
          sold: [];
          soldTo: [buyer: FactoryModelRef<"User">, soldAt?: string | Date | null];
        };
        relations: { seller: "User" };
      };
      User: {
        attributes: { name?: string };
        model: { id: number; name?: string };
        states: {};
        relations: {};
      };
      StandaloneFactoryModel: {
        attributes: { active?: boolean | null; id?: number };
        model: { id: number; active?: boolean | null };
        factoryRequired: "active";
        states: {};
        relations: {};
      };
    };
    operations: {
      "listing.sell": { input: { id: number }; output: { sold: boolean } };
      "model.partial": { input: Record<string, never>; output: ModelResult<"StandaloneFactoryModel"> };
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
  const discovered = await laravel.create("StandaloneFactoryModel", { attrs: { active: true } });
  expectTypeOf(discovered.active).toEqualTypeOf<boolean | null>();
  expectTypeOf((await laravel.call("model.partial")).active).toEqualTypeOf<boolean | null | undefined>();
  expectTypeOf((await laravel.model("StandaloneFactoryModel").firstOrFail()).active)
    .toEqualTypeOf<boolean | null | undefined>();

  const fluentUser = await laravel.factory("User").create({ name: "Ada" });
  const fluentListing = await laravel.factory("Listing")
    .for(fluentUser, "owner")
    .sold()
    .soldTo(fluentUser, new Date())
    .state({ price: 200 })
    .create({ title: "Table" });
  expectTypeOf(fluentListing.id).toEqualTypeOf<number>();
  const [first, second] = await laravel.factory("Listing").sold().createMany([{ title: "A" }, { title: "B" }]);
  expectTypeOf(first.id).toEqualTypeOf<number>();
  expectTypeOf(second.title).toEqualTypeOf<string | undefined>();
  const many = await laravel.factory("Listing").count(3).sold().create((_, i) => ({ title: `L${i}` }));
  expectTypeOf(many[0].id).toEqualTypeOf<number>();
  await laravel.factory("Listing").create({ display_title: "Chair" });
  await laravel.model("Listing").where({ title: "Chair" }).where("price", 10).get();

  // @ts-expect-error factory-only setter is not a database column
  laravel.model("Listing").where({ display_title: "Chair" });
  // @ts-expect-error serialized accessor is not a database column
  laravel.model("Listing").where("summary", "Chair");
  // @ts-expect-error wrong model passed to soldTo
  await laravel.factory("Listing").soldTo(fluentListing).create();
  // @ts-expect-error unknown state on fluent builder
  await laravel.factory("Listing").nonexistent().create();
  // @ts-expect-error invalid state argument type
  await laravel.factory("Listing").soldTo(fluentUser, 123).create();
  // @ts-expect-error discovered attributes are typed too
  await laravel.create("StandaloneFactoryModel", { attrs: { active: "yes" } });
  // @ts-expect-error states must be registered
  await laravel.create("StandaloneFactoryModel", { state: "active" });
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
