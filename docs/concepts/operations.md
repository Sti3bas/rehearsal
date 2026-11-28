# Named operations

Use operations when test setup or observation needs application logic that a factory or a [model query](./model-queries) cannot express. A named PHP handler runs inside the current attempt, so it can call your services and write to the isolated database without adding a production API route.

## Discover an invokable class

Save this as `app/Rehearsal/ReadUser.php` in the Laravel project. The example assumes a standard numeric User primary key.

```php
<?php

namespace App\Rehearsal;

use App\Models\User;
use Sti3bas\Rehearsal\Attributes\Operation;

#[Operation(
    name: 'users.read',
    rules: ['id' => 'required|integer|min:1'],
    types: ['output' => '{ id: number; name: string }'],
)]
final class ReadUser
{
    public function __invoke(int $id): array
    {
        $user = User::query()->findOrFail($id);

        return ['id' => $user->getKey(), 'name' => $user->name];
    }
}
```

Generate the TypeScript declaration, then call the operation by name:

```ts
const user = await laravel.factory("User").create({ name: "Ada" });
const result = await laravel.call("users.read", { id: user.id });
expect(result.name).toBe("Ada");
```

Without a generated definition, use `laravel.operation<{ id: number; name: string }>("users.read", { id })` for a manually typed result. Neither TypeScript form replaces the PHP validator. Rehearsal rejects unregistered names; keep fixture setup in named handlers that call your application's own services rather than adding a generic command endpoint.

## Argument binding

- A handler can take no parameters. A closure or an invokable method with one untyped, `array`, or `iterable` parameter receives the whole payload. Other handlers bind payload fields to parameters by name (for example, the `id` field binds to `$id`).
- When rules are present, Rehearsal validates the payload before invoking the handler. For named parameters with no supplied field, it uses a declared default or passes null if allowed; otherwise the call fails.
- Return JSON-serializable values. Use `types.input` and `types.output` when inference cannot describe the contract accurately, such as for a nested payload or an array-shaped response.

Use [the attribute reference](../reference/attributes#operation) for method-level discovery and the [extension guide](../guide/extending) for explicit registration or reusable TypeScript fixtures.
