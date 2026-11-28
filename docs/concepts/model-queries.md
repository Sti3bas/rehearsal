# Eloquent model queries

`laravel.model(alias)` uses a [factory registry alias](./factories) to query the real Eloquent model in the attempt database. Use it for observations that aren't visible in the browser, or a focused update to arrange an edge case. Prefer a named operation for business logic that needs joins, relationships, or additional validation.

```ts
await laravel.factory("User").create({ name: "Ada", email: "ada@example.test" });

const ada = await laravel.model("User")
  .where({ email: "ada@example.test" })
  .firstOrFail();

expect(ada.name).toBe("Ada");
expect(await laravel.model("User").where("id", ada.id).exists()).toBe(true);

const recent = await laravel.model("User")
  .whereNotNull("email_verified_at")
  .orderBy("id", "desc")
  .limit(10)
  .pluck("email");
```

## Build a query

| Call | Effect |
| --- | --- |
| `where({ email: "ada@example.test" })` | Adds equality filters for each field. |
| `where("id", 1)` | Adds a single equality filter. |
| `where("name", "like", "Ad%")` | Compares using `=`, `!=`, `<`, `<=`, `>`, `>=`, or `like`. |
| `whereIn(column, values)`, `whereNotIn(column, values)` | Filters by membership in a list. |
| `whereNull(column)`, `whereNotNull(column)` | Checks whether a value is null. |
| `orderBy(column, direction?)` | Adds sorting; defaults to ascending. |
| `limit(n)` | Limits the query to 1–1000 rows. |

Filters are combined with AND and normal Eloquent global scopes apply. Columns must be simple identifiers, not raw expressions. The server accepts up to 50 filters and 10 sort clauses. With generated types, model columns and row results are inferred from the schema.

## Execute the query

| Call | Result |
| --- | --- |
| `first()` / `firstOrFail()` | First model or null; the latter fails the call if no model matches. |
| `get()` | Array of serialized models. |
| `pluck(column)` | Array of values from that column. |
| `value(column)` | First matching value or null. |
| `count()` / `exists()` | Number of matches (capped by a supplied limit), or whether there is a match. |
| `update(values)` / `delete()` | Number of models successfully saved or deleted. |

`update` and `delete` fetch matching models and save or delete each within a transaction, so Eloquent events and model delete behavior apply. Updates use `forceFill`, bypassing mass-assignment protection. Always scope mutations explicitly unless you mean to change every matching row in the attempt.
