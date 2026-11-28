# Scout: Database engine

The database engine runs Scout searches directly against the [restored slot database](../database). It does not create remote indexes or require a search server, a separate client, or an admin key. The rows in the database baseline are available to search as soon as the attempt starts.

## Configure

Install Laravel Scout and keep `Searchable` on the app models. Set `REHEARSAL_SCOUT_ENGINE=database` in `.env.rehearsal`. Rehearsal discovers the [Scout integration](./) from that engine setting when discovery is enabled. There's no remote model or schema setup.

## In a test

Create a searchable model using the ordinary factory, then exercise the app's existing search UI or route:

```ts
await laravel.factory("Listing").create({ title: "Oak desk" });
await page.goto("/search?q=Oak");
await expect(page.getByText("Oak desk", { exact: true })).toBeVisible();
```

This assumes the application exposes that search route and includes `title` in its searchable data. Database search follows the selected SQL engine's behavior; it does not simulate Typesense or Meilisearch relevance, filters, or indexing.
