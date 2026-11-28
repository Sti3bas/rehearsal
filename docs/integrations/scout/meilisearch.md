# Scout: Meilisearch

Use Meilisearch when a test needs real index settings, filters, or ranking. Rehearsal creates namespaced indexes per worker slot and clears their documents between attempts without dropping and rebuilding the indexes.

## Configure

Install Scout and the Meilisearch PHP client. Start a disposable Meilisearch server on loopback and choose the engine:

```dotenv [.env.rehearsal]
REHEARSAL_SCOUT_ENGINE=meilisearch
REHEARSAL_SCOUT_ORIGIN=http://127.0.0.1:7700
REHEARSAL_SCOUT_ADMIN_KEY=your-disposable-admin-key
```

The engine setting enables [Scout discovery](./#availability-and-discovery) when discovery is on. Discover or list your searchable models as usual. Rehearsal applies `scout.meilisearch.index-settings` keyed by model or index name. If Scout soft-delete support is enabled for a soft-deletable model, Rehearsal includes the soft-delete field in the index's filterable attributes.

## In a test

Search through the app's backend. For example, if the app registers a [named query operation](../../concepts/operations) called `listings.search` that returns matching titles:

```ts
await laravel.factory("Listing").create({ title: "Oak desk" });
await expect.poll(() => laravel.operation("listings.search", { query: "Oak" }))
  .toEqual(["Oak desk"]);
```

::: tip Seed remote documents in the test
Unlike the database engine, Meilisearch starts with empty indexes on reset. Do not assume that rows in the database baseline are already indexed. Use factories or app setup to create the documents the test needs. The `baseline_models` option is Typesense-only.
:::

Rehearsal does not expose a browser search-key helper for Meilisearch. Keep the slot's backend credentials on the server.
