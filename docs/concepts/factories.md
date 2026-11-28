# Factories and generated types

Factories create real Eloquent models in the current attempt database. Given a standard `UserFactory`, use either the fluent form or pass attributes directly:

```ts
const ada = await laravel.factory("User").create({ name: "Ada" });
const grace = await laravel.factory("User", { name: "Grace" });
const users = await laravel.factory("User").count(3).create();
const namedUsers = await laravel.factory("User").createMany([
  { name: "Lin" },
  { name: "Sam" },
]);
```

Chain named states before creation, set attributes with `.state(attrs)`, and pass a previously returned model to `.for(model, relation?)` for a belongs-to relation. `.create(overrides?)` applies final attributes on top of the builder. Direct calls also accept `{ states, count }`; use a state name or `{ name, args }` for an argument-taking state.

## Factory discovery

Rehearsal discovers autoloadable Eloquent models in `app/Models` using Laravel's factory resolution, and looks for standalone Eloquent factories in `database/factories`. Use the exact model basename (for example, `User`). If two models share a basename, define explicit aliases instead. A model's own factory hook takes precedence over a discovered standalone factory. An explicit `factory` class in config overrides both and uses the configured model unless the factory declares a model itself.

Public fluent state methods on each factory class (methods returning `static` / `self` / `Factory` with serializable parameters: scalars, arrays, backed enums, `DateTimeInterface`/`Carbon`, and Eloquent `Model`s or `Model` unions) are discovered automatically and emitted by `rehearsal:types`. Returned models carry a non-enumerable alias tag so they can be passed directly into `.for(model, relation?)` or typed state methods without affecting JSON serialization or equality checks. Set `'discover_states' => false` under `rehearsal.factories` (or `'states' => false` on an alias) to disable automatic state discovery.

Set `factories.model_paths` or `factories.factory_paths` in `config/rehearsal.php` to replace either list of scan directories. Paths can be absolute or relative to the Laravel root. Entries under `factories` can add aliases, override a discovered model, or supply only metadata such as `states`, `relations`, or `types` for an existing alias. Set `factories.discover` to `false` to use only explicitly configured factories.

Managed runs discover factories once at startup and reuse that list across requests. Restart the run to pick up new factories. Type generation discovers factories independently. Discovery does not instantiate factories or call model factory hooks. If a standalone factory depends on a custom model-name resolver, declare its model with `#[UseModel]` or a default `$model` property, or register it explicitly. The factory operation still requires the managed, test-only control plane.

## Generate types from your Laravel app

Run `php artisan rehearsal:types ../frontend/rehearsal.generated.d.ts --env=rehearsal` from the Laravel root, then include the resulting file in your Playwright TypeScript project. Discovered and explicitly configured factories are both included. Use the environment that registers your fixtures and operations. For a Playwright global setup, import `generateRehearsalTypes` from `@sti3bas/rehearsal` and await `generateRehearsalTypes({ root: "../backend", path: "../frontend/rehearsal.generated.d.ts", environment: "rehearsal" })`; the output path is relative to the Laravel root. Generate before running a separate TypeScript check.

The declaration augments the Playwright fixture and the `Laravel` client. Without it, the client accepts string aliases and returns generic rows. With it, editors suggest the registered aliases and infer model and operation results. This example assumes an app-specific `Listing` factory with a `sold` state and a registered `listing.observe` operation:

```ts
const listing = await laravel.create("Listing", { state: "sold", attrs: { title: "Chair" } });
const result = await laravel.call("listing.observe", { id: listing.id });
const [seller, listings] = await laravel.factory([
  { model: "User" },
  { model: "Listing", count: 2, refs: { seller: 0 } },
]);
```

- Factory properties are inferred from Eloquent fillable names, casts, defaults, `@property` tags, and column metadata (reading the model's connection when migrated, or falling back to an ephemeral in-memory SQLite migration of `database/migrations`). Known casts take precedence over property annotations, except that an explicit `@property-write` type describes factory input only. Pass `--database=sqlite` (or another **disposable, migrated** named connection) to read columns from a specific connection explicitly.
- Literal attribute names in a factory definition that directly returns an array (`[...]` or `array(...)`) can become required in factory results, but not in model queries or named operation results. Rehearsal reads the definition without running it and only infers guarantees for visible stored attributes, excluding names with mutators. With column metadata, model queries accept only column names, not virtual factory inputs or serialized accessors.
- Generate against the same migrated connection in development and CI if you rely on inferred guarantees. For example, a literal `slug` becomes required if Rehearsal sees the column. If column metadata is unavailable, only fields with model defaults can get that guarantee. For stable types without a migrated schema or for values assigned by a dynamic definition or callback, configure `types.required` explicitly; also supply `types.model` if the field is otherwise undiscovered. Use `types.attributes` to customize input types. Output overrides should describe the actual serialized JSON.
- Register a batch relation with `'relations' => ['seller' => 'User']` on the `Listing` factory. Use an Eloquent `belongsTo` method name and the related factory alias. `refs` accepts a zero-based index of an earlier **single** result, which supplies the foreign key for all records in the referring item. The server validates the order, alias, and relation.
- `laravel.call` types named operations, including those registered by a provider. Input inference handles simple validation rules and output inference handles basic PHP return types. For nested objects, conditional rules, or custom resources, specify `'types' => ['input' => '{ id: number }', 'output' => '{ status: string }']` in the operation config, or pass the same map as the fourth argument to `Operations::register`. `laravel.operation<T>` remains available for dynamic or manually typed calls. Artisan aliases can likewise declare `types.input`; schedule names and server fault paths are inferred directly from their allowlists.

Next: [query your attempt's Eloquent models](./model-queries) or [override a factory alias](../guide/extending).
