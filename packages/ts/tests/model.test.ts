import { describe, expect, it } from "vitest";
import { createModelQuery } from "../src/model.js";
import { rehearsalModel } from "../src/schema.js";

describe("createModelQuery", () => {
  it("encodes where, order, limit, read, and write payloads and tags returned models", async () => {
    const calls: Record<string, unknown>[] = [];
    const send = async <T>(payload: Record<string, unknown>): Promise<T> => {
      calls.push(payload);
      if (payload.action === "first") return { id: 7, slug: "chair" } as T;
      if (payload.action === "get") return [{ id: 7 }, { id: 8 }] as T;
      if (payload.action === "pluck") return [7, 8] as T;
      if (payload.action === "value") return 7 as T;
      if (payload.action === "count") return 2 as T;
      if (payload.action === "exists") return true as T;
      if (payload.action === "update") return 1 as T;
      return 1 as T;
    };

    const query = createModelQuery(send, "Listing" as never);
    const first = await query.where({ slug: "chair" } as never).first();
    expect(first).toEqual({ id: 7, slug: "chair" });
    expect((first as Record<PropertyKey, unknown>)[rehearsalModel]).toBe("Listing");

    const ids = await query.whereNull("deleted_at" as never).orderBy("id" as never, "desc").limit(5).pluck("id" as never);
    expect(ids).toEqual([7, 8]);

    expect(await query.where("id" as never, 7 as never).update({ title: "Table" } as never)).toBe(1);
    expect(calls).toEqual([
      {
        model: "Listing",
        where: [{ column: "slug", operator: "=", value: "chair" }],
        action: "first",
      },
      {
        model: "Listing",
        where: [{ column: "deleted_at", operator: "null" }],
        orderBy: [{ column: "id", direction: "desc" }],
        limit: 5,
        action: "pluck",
        column: "id",
      },
      {
        model: "Listing",
        where: [{ column: "id", operator: "=", value: 7 }],
        action: "update",
        values: { title: "Table" },
      },
    ]);
  });
});
