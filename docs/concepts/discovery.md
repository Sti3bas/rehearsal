# Auto-discovery

Rehearsal uses Laravel's registrations and autoloadable classes to find test helpers. Discovery is on by default for the features below. It does not register arbitrary PHP files as runnable handlers.

## What is discovered

| Feature | What Rehearsal looks for | Configure or narrow it |
| --- | --- | --- |
| Factory aliases | Eloquent models with factories in `app/Models`; standalone factories in `database/factories` | `factories.discover`, `factories.model_paths`, `factories.factory_paths`; explicit aliases resolve basename collisions. |
| Factory states | Public instance methods returning a factory and accepting supported arguments | `factories.discover_states` or per-alias `states`. |
| Operations | Invokable classes and declared public methods carrying `#[Operation]` | `operations.discover`, `operations.paths`; replace an entry by name or set it to `false`. |
| Artisan | Commands registered with Laravel's Artisan application | `artisan.discover`; prefer validated aliases when you need a narrow surface. |
| Scheduled work | Laravel scheduler entries that run Artisan commands; fixed arguments can form part of the alias | `schedule.discover`; add or replace a task by alias, or set it to `false`. Scheduled closures and arbitrary shell events aren't runnable helpers. |
| Fault paths | Runtime discovery permits app paths other than `/_rehearsal` and its subpaths. The type generator reads Laravel's routes to emit typed paths. | `fault_paths`; replace with a list of exact paths when runtime restriction is needed. |
| Callbacks | Concrete `CallbackReference` implementations with `#[Callback]` and a path | `callbacks.discover`, `callbacks.paths`; override or disable by exact path. Discovery does not add the application's webhook route. |
| Integrations | Concrete `Integration` implementations plus eligible Mailpit, Scout, and Reverb built-ins | `integrations.discover`, `integrations.paths`; register classes explicitly for predictable ordering or disable by key (class name for an auto-discovered app integration). |
| Scout models | Concrete classes using Scout's `Searchable` trait under `app/Models` and the helper paths below | `scout.models.discover` and `scout.models.paths`, or supply an explicit list. |

Queue names are inferred independently from the database queue and Horizon configuration unless you supply `rehearsal.queues`.

## Put helpers on an autoloaded path

The default class scan directories for operations, callbacks, and integrations are `tests/Rehearsal`, `tests/Helpers`, and `app/Rehearsal`. Paths are relative to the Laravel root unless absolute. A class must be autoloadable through Composer; use the app namespace for `app/Rehearsal`, or check your consuming project's `autoload-dev` mapping for classes under `tests/`.

For example, scan only a custom operation directory:

```php
'operations' => [
    'discover' => true,
    'paths' => ['app/Testing/Operations'],
],
```

Run [type generation](./factories#generate-types-from-your-laravel-app) against the same environment used by tests so declarations reflect its discovered and explicit entries. Restart the managed run to pick up factory discovery changes.
