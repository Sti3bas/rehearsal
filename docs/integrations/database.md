# Database <Badge type="tip" text="Built in" />

Rehearsal migrates once per run and restores the database before every test attempt. Your application and the `laravel` fixture use the same isolated database, so browser requests see records created through Eloquent factories.

| Engine | How it resets |
| --- | --- |
| SQLite (default with the installed environment) | Copies the migrated baseline file into the worker slot. |
| MySQL | Creates a slot database and copies tables and data from the run's template. |
| PostgreSQL | Clones a slot database from the run's PostgreSQL template. |

## Configure

`database` is registered in the shipped integration map, not discovered from your models. Keep `'database' => \Sti3bas\Rehearsal\Database::class` in that map if you publish the config.

Set `rehearsal.database.seeder` to the class to run after migrations, before taking the baseline. Use `database.aliases` for additional Laravel connection names that should point at the same slot database. It does not give each alias a separate database.

### MySQL or PostgreSQL

Choose the engine in `.env.rehearsal`. You can also pass the managed connection values through [`laravelServer({ database })`](../reference/typescript#laravelserver).

::: code-group

```dotenv [MySQL]
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=rehearsal
DB_PASSWORD=your-local-test-password
REHEARSAL_DB_DISPOSABLE=true
```

```dotenv [PostgreSQL]
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_USERNAME=rehearsal
DB_PASSWORD=your-local-test-password
REHEARSAL_DB_DISPOSABLE=true
```

:::

::: warning Use a disposable loopback server
The database account must be able to create and drop databases. The MySQL template covers tables and data, not views, triggers, routines, or events. Use your application's actual engine when a test depends on its SQL semantics.
:::

For SQLite, the default transaction mode is `DEFERRED`. With PHP 8.4+, use `REHEARSAL_SQLITE_TRANSACTION_MODE=IMMEDIATE` when concurrent requests in the same attempt read before writing and you want them to wait at transaction start. `EXCLUSIVE` is also accepted.

## In a test

```ts
await laravel.factory("User").create({ email: "ada@example.test" });
expect(await laravel.model("User").where("email", "ada@example.test").exists()).toBe(true);
```

See [factories](../concepts/factories), [model queries](../concepts/model-queries), and the [database configuration reference](../guide/configuration#database-and-storage).
