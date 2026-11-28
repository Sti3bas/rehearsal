# Artisan commands

Run these commands from the Laravel project root. In a split project, generated declaration paths are still relative to this directory.

| Command | Purpose |
| --- | --- |
| `php artisan rehearsal:install [--environment=rehearsal]` | Creates a dedicated `.env.<environment>` with a fresh app key and test defaults. Never overwrites an existing file. A custom environment is added to the allowlist in the generated file. |
| `php artisan vendor:publish --tag=rehearsal-config` | Publishes the package's config as `config/rehearsal.php`. |
| `php artisan rehearsal:types <path> [--database=<connection>] [--env=<environment>]` | Writes the TypeScript module augmentation. Use Laravel's `--env` to match the environment registering your helpers. An explicit database connection should be disposable and already migrated. |
| `php artisan rehearsal:provision` | Internal managed-run command. Creates the baseline and provisions integrations. |
| `php artisan rehearsal:cleanup` | Internal managed-run command. Cleans up the current run's resources. |

The last two commands are registered only when the bridge is active. The Node launcher manages them for you: configure Playwright with [`laravelServer()`](./typescript#laravelserver), not ad hoc provisioning commands.

```sh
php artisan rehearsal:types ../frontend/rehearsal.generated.d.ts --env=rehearsal
```

Use `npx playwright test` from the Playwright project to run the suite. To run commands from inside a test, see [Artisan aliases and scheduled tasks](../guide/extending#restrict-artisan-and-scheduled-work); those run against the current attempt and are separate from the commands above.
