# Laravel Scout <Badge type="info" text="Optional" />

Rehearsal lets Laravel Scout run your application's normal search code while isolating the underlying records or indexes. Choose the same engine your application uses if the test depends on its ranking, filtering, or index settings.

<DocGrid :items="[
  { title: 'Database engine', href: '/integrations/scout/database', description: 'Search the restored Laravel slot database without an external service.' },
  { title: 'Meilisearch', href: '/integrations/scout/meilisearch', description: 'Use real indexes with your app’s Meilisearch settings and backend queries.' },
  { title: 'Typesense', href: '/integrations/scout/typesense', description: 'Test collections, references, seeded baselines, and scoped browser search.' },
]" />

## Availability and discovery

Install Laravel Scout and the chosen engine's PHP client if needed. With `integrations.discover` on, Rehearsal registers Scout when Laravel Scout is installed and `rehearsal.scout.engine` (or its environment fallback) selects `database`, `meilisearch`, or `typesense`. With discovery off, add `'scout' => \Sti3bas\Rehearsal\Scout::class` to the existing integration map.

The remote engines use a loopback origin and a disposable admin key. Rehearsal creates per-slot indexes or collections, resets their documents between attempts, and removes owned resources at cleanup. Your app uses a narrower, slot-scoped key; do not expose administrative or backend keys in a browser.

## Searchable models

Remote engines need at least one searchable model. By default Rehearsal discovers concrete classes using Scout's `Searchable` trait. Configure scan paths or list the models explicitly in `rehearsal.scout.models` (for example, `[\App\Models\Listing::class]`). See [auto-discovery](../../concepts/discovery).

::: warning Preserve the slot prefix
If the app customizes `searchableAs()` or `indexableAs()`, they must resolve to the same name and honor `scout.prefix` for remote engines. Without the prefix, Rehearsal refuses to create or reset the index.
:::

Scout queueing is turned off for managed attempts. For remote engines, model indexing runs after database commit, but search visibility can still be asynchronous. Poll a search assertion rather than adding a fixed delay.
