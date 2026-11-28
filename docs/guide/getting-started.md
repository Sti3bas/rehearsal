---
description: What Rehearsal does, how it isolates Laravel state, and when to use it.
---

# Introduction

Rehearsal connects a real Laravel application to Playwright Test. Use it when your browser tests need more than a page that renders: they need users to sign in, records to search for, jobs to run, or email to arrive.

## Why Rehearsal?

Imagine two tests running at once. One creates a customer, and the other checks that a new customer can register. If they share a database or a queue, the result can depend on which worker gets there first. Cleaning up at the end of a test isn't enough: a failure or retry can leave state behind, and another worker may already have read it.

Rehearsal gives every test attempt a clean starting point while sharing a single Laravel HTTP server. It keeps the Playwright pieces you already use: browser fixtures, traces, retries, and reporters.

<DocGrid :items="[
  { title: 'Run in parallel', href: '/concepts/isolation', description: 'Reset database, files, sessions, cache, and queues before each attempt.' },
  { title: 'Arrange in TypeScript', href: '/concepts/factories', description: 'Create real Eloquent records and generate types from your app’s factories and operations.' },
  { title: 'Test the real service', href: '/integrations/', description: 'Read rendered emails, query a real search engine, or verify a WebSocket event.' },
  { title: 'Use your frontend', href: '/frontends/', description: 'Test Laravel-rendered pages, an Inertia app, or a Nuxt frontend on a separate server.' },
]" />

## How it works

1. **Provision:** Playwright starts the managed Laravel server. Rehearsal migrates and optionally seeds a database baseline once.
2. **Reset:** Before each test or retry, Rehearsal restores that worker's lane from the baseline and resets the other integrations.
3. **Bind:** The fixture tags the browser's requests so Laravel can use the right state for this attempt. The test uses normal Playwright APIs and can arrange data with the `laravel` fixture.
4. **Clean up:** At the end of the run, Rehearsal removes the disposable resources it created.

::: tip One server, isolated attempts
A replacement worker can reuse the same lane, but its next test gets a fresh reset. Read [runs, slots, and attempts](../concepts/isolation) for how this works across retries and SSR.
:::

## When to use something else

- Choose **Laravel Dusk** or **Pest Browser Testing** if you want to write browser tests in PHP alongside your Laravel tests.
- Use **Playwright on its own** if you already provision isolated state outside the runner, or your tests only use public interfaces and don't need Laravel fixtures.
- Use ordinary **Laravel feature tests** when the behavior does not need a real browser. Keep browser tests for journeys that cross the application and frontend boundary.

## Run your first test

Start with [Installation](./installation): install the Laravel and Playwright packages, configure the managed server, and run a smoke test.
