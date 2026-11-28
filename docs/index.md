---
layout: home

hero:
  name: Rehearsal
  text: Parallel Playwright testing for Laravel
  tagline: Test a real Laravel app with Playwright. Every attempt gets clean state, even when tests run in parallel or retry.
  image:
    src: /rehearsal-mark.svg
    alt: Rehearsal logo
  actions:
    - theme: brand
      text: Get started
      link: /guide/getting-started
    - theme: alt
      text: Install
      link: /guide/installation

features:
  - title: Parallel by design
    details: Share a Laravel server while isolating the database, files, sessions, cache, and queues behind each test.
    link: /concepts/isolation
  - title: Arrange tests from TypeScript
    details: Use Eloquent factories and named PHP operations, then generate types from your actual Laravel app.
    link: /concepts/factories
  - title: Keep using Playwright
    details: Write ordinary browser tests with traces and retries. Add your own fixtures, or plug a new service into the run lifecycle.
    link: /guide/extending
  - title: Test with real services
    details: Add Mailpit, Laravel Scout, or Reverb when you need to verify email, search, or broadcast behavior.
    link: /integrations/
---
