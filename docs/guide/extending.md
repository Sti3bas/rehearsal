# Extend and override

Start with configuration overrides if you only need to change a default or narrow discovery. Add a [named operation](../concepts/operations) for app-specific setup; add an integration when a resource needs its own lifecycle.

## Write an integration

See [Writing your own integration](../integrations/writing-your-own) for the lifecycle hooks, registration, and a complete example.

## Override a factory or state

```php
'factories' => [
    'discover' => true,
    'Member' => [
        'model' => \App\Models\User::class,
        'factory' => \Database\Factories\UserFactory::class,
        'states' => ['teamMember' => 'staff', 'staff' => false],
        'types' => ['required' => ['id', 'name']],
    ],
],
```

Call `laravel.factory("Member").teamMember().create()` with the [example UserFactory](https://github.com/Sti3bas/rehearsal/blob/main/examples/laravel/database/factories/UserFactory.php), which defines `staff()`. The alias calls that method, while `'staff' => false` hides its automatically discovered name for `Member`. To turn off automatic state discovery on a single alias, set `states` to `false`. To restrict the factory registry itself to an explicit set, set `factories.discover` to `false` and list every alias the tests need.

## Register or replace an operation

An explicit entry replaces a discovered operation with the same name; an entry set to `false` removes it. Specify a handler class, optional `method` (default `__invoke`), validation `rules`, and optional TypeScript `types`.

```php
'operations' => [
    'discover' => true,
    'users.read' => [
        'handler' => \App\Rehearsal\ReadUser::class,
        'rules' => ['id' => 'required|integer|min:1'],
        'types' => ['output' => '{ id: number; name: string }'],
    ],
],
```

Alternatively, register an operation in a service provider's `boot()` method. Use the same test environment for registration and type generation:

```php
use Illuminate\Support\Facades\Storage;
use Sti3bas\Rehearsal\Operations;

if ($this->app->environment('rehearsal')) {
    $this->app->make(Operations::class)->register(
        'reports.stage',
        ['title' => 'required|string|max:100'],
        fn (array $data): bool => Storage::disk('local')->put('report.txt', $data['title']),
    );
}
```

Provider registration cannot reuse an already registered name. An optional fourth argument to `register` supplies `types.input` and `types.output` overrides as an array with `input` and `output` keys.

## Compose a Playwright fixture

Use the typed `operations` fixture if the app shares operation definitions without using generated types. Alias names such as `stageReport` are independent of the PHP operation name.

```ts
import { defineOperation, defineRehearsal } from "@sti3bas/rehearsal";

const rehearsal = defineRehearsal({
  operations: {
    stageReport: defineOperation<{ title: string }, boolean>("reports.stage"),
  },
});

export const test = rehearsal.extend<{ stagedReport: boolean }>({
  stagedReport: async ({ operations }, use) => {
    await use(await operations.stageReport({ title: "Weekly summary" }));
  },
});
```

With generated types, you can also extend the original `test` and call `laravel.call("reports.stage", input)` in the fixture.

## Restrict Artisan and scheduled work

Use purpose-built aliases rather than enabling every registered command. In this example, `reports:refresh` is an Artisan command defined by the application with a `--scope` option.

```php
'artisan' => [
    'discover' => false,
    'refresh-reports' => [
        'command' => 'reports:refresh',
        'fixed' => ['--scope' => 'weekly'],
    ],
],
'schedule' => [
    'discover' => false,
    'weekly-reports' => [
        'command' => 'reports:refresh',
        'fixed' => ['--scope' => 'weekly'],
    ],
],
```

Call `laravel.artisan("refresh-reports")` or `laravel.schedule.run("weekly-reports")`. An Artisan alias can declare `rules` for caller-supplied arguments and `types.input` for the generated declaration. Fixed arguments take precedence over supplied arguments. Scheduled aliases use fixed arguments and take no runtime arguments; `schedule.run` accepts an optional queue drain after successful commands.

For other settings, see the [configuration reference](./configuration).
