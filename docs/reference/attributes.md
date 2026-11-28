# PHP attributes

Both package attributes live under `Sti3bas\Rehearsal\Attributes`. Put annotated classes in [an autoloaded discovery directory](../concepts/discovery#put-helpers-on-an-autoloaded-path).

## Operation

`#[Operation(?string $name = null, array $rules = [], array $types = [])]` targets a class or a method.

| Argument | Meaning |
| --- | --- |
| `name` | Unique operation name. Defaults to the kebab-case class basename for an invokable class, or `class-basename.method-name` in kebab case for a method. |
| `rules` | Laravel validation rules for input. When empty on a class attribute, a public `rules()` method can supply them. |
| `types` | Optional `input` and `output` TypeScript expressions. When empty on a class attribute, a public `types()` method can supply them. |

Class-level operations require an instantiable class with `__invoke`. Method-level operations use public methods declared on the class and support both static methods and instance methods resolved through Laravel's container.

```php
namespace App\Rehearsal;

use Sti3bas\Rehearsal\Attributes\Operation;

final class StringTools
{
    #[Operation(rules: ['value' => 'required|string|max:100'])]
    public static function length(string $value): int
    {
        return mb_strlen($value);
    }
}
```

Call the example as `laravel.call("string-tools.length", { value: "hello" })`. See [named operations](../concepts/operations) for an invokable example, type generation, and how payloads are bound.

## Callback

`#[Callback(?string $path = null)]` targets a concrete class implementing `Sti3bas\Rehearsal\CallbackReference`. Supply the callback path in the attribute or return it from a public static `path()` method. Empty or missing paths are ignored. The discovered path is normalized to start with `/`.

```php
#[\Sti3bas\Rehearsal\Attributes\Callback('/provider/callback')]
```

The verifier implements `verifiedReference(Request $request): ?string`, returning a stable provider reference only after verifying the signature. The attribute makes the verifier discoverable; it does not create the application's POST route. See [verified callback routing](../concepts/mocking#route-verified-callbacks) for binding the reference.

## Laravel factory attributes

Laravel's own factory/model resolution still applies. A standalone factory can declare its model with Laravel's `#[UseModel]` attribute or a default `$model` property. These declarations are not Rehearsal attributes; see [factory discovery](../concepts/factories#factory-discovery).
