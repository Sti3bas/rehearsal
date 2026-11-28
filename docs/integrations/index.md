---
description: Pick the integrations that isolate Laravel state and real local services for each test.
---

# Integrations

Integrations prepare and reset the state behind each test. You get the core Laravel integrations automatically; opt into local services when a test needs to verify real email, search, or broadcasting.

## Included by default

<DocGrid :items="[
  { title: 'Database', href: '/integrations/database', badge: 'Built in', description: 'Restore a migrated, optionally seeded database per worker lane on SQLite, MySQL, or PostgreSQL.' },
  { title: 'Filesystem', href: '/integrations/filesystem', badge: 'Built in', description: 'Keep uploads, generated files, and disk URLs scoped to a test attempt.' },
  { title: 'Laravel State', href: '/integrations/laravel-state', badge: 'Built in', description: 'Isolate sessions, cache, rate limits, and database queues.' },
  { title: 'Clock', href: '/integrations/clock', badge: 'Built in', description: 'Set Laravel’s test time and Playwright’s page clock from a spec.' },
]" />

## Optional services

<DocGrid :items="[
  { title: 'Mailpit', href: '/integrations/mailpit', description: 'Read delivered email, assert subjects, and follow links from the current attempt’s inbox.' },
  { title: 'Laravel Scout', href: '/integrations/scout/', description: 'Run your application’s existing Scout queries against an isolated database or search engine.' },
  { title: 'Reverb', href: '/integrations/reverb', description: 'Verify channel subscriptions and events on a real WebSocket connection.' },
]" />

Laravel Scout supports three engines:

| Engine | Choose it when |
| --- | --- |
| [Database](./scout/database) | Your application uses database search and doesn't need a separate search process. |
| [Meilisearch](./scout/meilisearch) | You need your app's Meilisearch index and query behavior. |
| [Typesense](./scout/typesense) | You need Typesense schemas, baseline indexing, or direct browser search. |

::: warning Use disposable services
Run services on loopback with test-only credentials. Rehearsal creates, resets, and deletes resources. Never point the test app at production or a developer's shared service.
:::

## Add your own

[Write a custom integration](./writing-your-own) when an app-specific service needs its own lifecycle. Use [auto-discovery](../concepts/discovery) or register it explicitly to control hook order.

See the [working Laravel config](https://github.com/Sti3bas/rehearsal/blob/main/examples/laravel/config/rehearsal.php) and the [example Compose services](https://github.com/Sti3bas/rehearsal/blob/main/examples/laravel/compose.integrations.yml) for a working setup.
