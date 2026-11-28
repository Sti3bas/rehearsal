# Scout: Typesense

Rehearsal prepares slot-specific Typesense collections for your application's Scout models. It supports collection references, optional baseline imports, and a read-only key for direct browser search.

## Configure

Install Scout and its Typesense PHP client, and run a disposable loopback Typesense server:

```dotenv [.env.rehearsal]
REHEARSAL_SCOUT_ENGINE=typesense
REHEARSAL_SCOUT_ORIGIN=http://127.0.0.1:8108
REHEARSAL_SCOUT_ADMIN_KEY=your-disposable-admin-key
```

The engine setting enables [Scout discovery](./#availability-and-discovery) when discovery is on. Define collection schemas as usual in `scout.typesense.model-settings.<model>.collection-schema` or via `typesenseCollectionSchema()`. When configuring an explicit model list, put referenced models before their dependents. Rehearsal rewrites schema references to the slot's collections.

### Start with seeded search data

The collections are empty on reset unless you opt into a baseline import:

1. Set `rehearsal.database.seeder` to seed the database baseline.
2. Keep Database before Scout in the integration map.
3. List each model to import under `rehearsal.scout.baseline_models`. Include it in the resolved Scout model list too.

Rehearsal imports those models from the restored slot database on every attempt.

## In a test

Use ordinary Scout-backed application search for backend behavior. For a browser that queries Typesense directly, pass `laravel.typesense()` to your frontend test setup:

```ts
const { origin, collectionPrefix, searchKey } = laravel.typesense();
const collection = `${collectionPrefix}listings`;
// Configure your search client with origin, collection, and X-TYPESENSE-API-KEY: searchKey.
```

Use the collection suffix for your model rather than assuming `listings`. The key permits document searches in that slot's collections only and is revoked at the next reset or teardown. Do not send the admin or backend write key to the browser.

::: warning Allow only the frontend origin
Configure CORS on the disposable Typesense server for your loopback frontend origin. If using Nuxt and InstantSearch, see [the Nuxt adapter helper](../../frontends/nuxt#typesense-instantsearch) for test-time configuration.
:::
