# Writing your own integration

Add an integration when a test dependency needs run-wide provisioning and per-attempt reset. For a single piece of fixture setup, use a [named operation](../concepts/operations) instead.

## Choose the right hook

| Hook | Called | Put this here |
| --- | --- | --- |
| `provision(Run $run)` | During managed run setup | Create run-level resources and verify the test service is reachable. |
| `reset(Attempt $attempt)` | Before each test or retry, under the slot lock | Prepare a clean slot from a baseline or clear only the resources the slot owns. |
| `bind(Attempt $attempt)` | For each request bound to the attempt | Apply Laravel config and reconnect services that already resolved a driver. |
| `cleanup(Run $run)` | During managed shutdown, in reverse order | Remove only the resources recorded as belonging to this run. |

`$attempt->path('suffix')` is scoped to the slot, not the individual retry. Clear or replace state at reset. `$attempt->id` identifies one attempt. Use the run ID to namespace resources outside the run directory.

## Register an example

An integration implements four lifecycle hooks. Register the class under a unique key in `rehearsal.integrations`. The registry resolves it through Laravel's container in configuration order, then cleans up in reverse.

```php
namespace App\Testing;

use Illuminate\Support\Facades\File;
use Sti3bas\Rehearsal\{Attempt, Integration, Run};

final class ReportScratch implements Integration
{
    public function provision(Run $run): void {}

    public function reset(Attempt $attempt): void
    {
        $path = $attempt->path('reports');
        if (is_dir($path) && ! File::deleteDirectory($path)) {
            throw new \RuntimeException('Cannot clear the report scratch directory.');
        }

        File::ensureDirectoryExists($path, 0700);
    }

    public function bind(Attempt $attempt): void
    {
        config(['reports.scratch_path' => $attempt->path('reports')]);
    }

    public function cleanup(Run $run): void {}
}
```

Add `'reports' => \App\Testing\ReportScratch::class` to the integration map. The app can now use `reports.scratch_path`. These files live inside the run directory, which the managed launcher removes. If your integration creates resources outside that directory, record what it owns and remove only those resources during cleanup.

Discovery can find concrete implementations under the [helper paths](../concepts/discovery). Register the class explicitly when it depends on an earlier hook (such as the Database integration). Rehearsal calls `provision` once per run, `reset` before each attempt, `bind` when applying attempt state, and `cleanup` in reverse order. If you replace a default, give the implementation ownership of the state the default used to isolate:

```php
// In the existing integrations array:
'laravel-state' => false,
'my-state' => \App\Testing\MyIsolatedState::class,
```

For integrations discovered by class name, disable the class by using its fully qualified class name as the key with value `false`. Disable all automatic integration discovery with `integrations.discover => false` while retaining any explicit entries.

## Publish browser-safe metadata

If a Playwright test needs public configuration, also implement `Sti3bas\Rehearsal\ClientMetadata` and return an array from `clientMetadata(Attempt $attempt)`. Rehearsal collects it just after your reset hook. Read it with `laravel.integrationMetadata("reports")` if your registration key is `reports`. Validate the result before use; its TypeScript type is `unknown`.

::: warning Keep credentials server-side
Return only information safe to hand to test or browser code. Never include an admin credential, publisher secret, or Rehearsal control capability.
:::
