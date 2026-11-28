# Runs, slots, and attempts

Playwright decides which browser test runs where. Rehearsal supplies isolated Laravel state to that test without launching a new Laravel server for every worker.

- A **run** is one managed Playwright server lifecycle. It owns the baseline and resources created during that run.
- A **slot** is a stable worker lane, derived from Playwright's `parallelIndex + 1`. A replacement worker can reuse the slot.
- An **attempt** is one test execution, including each retry. Before the test begins, Rehearsal resets the slot and grants a fresh signed routing marker. Requests carrying a retired marker are rejected.

The lifecycle is **provision → reset → bind requests → cleanup**. Rehearsal copies or clones the migrated database baseline rather than running migrations for every test.

Database and filesystem changes made by one attempt are not a setup mechanism for another. Arrange the state each test needs, or seed shared starting data into the [database baseline](../guide/services). In a retry, the replacement worker gets a fresh reset and marker even if it reuses the same slot.

## The isolation boundary

Rehearsal is **test-only**. The bridge activates only in an allowlisted environment with valid managed run settings, and the managed launcher listens on loopback. Keep it off public networks and use disposable databases and service credentials. Never publish the launch record or your test environment file.

The browser's signed, HTTP-only cookie routes ordinary app requests to the test attempt. Cookies do not distinguish ports, so run only trusted loopback services on that host. The separate control capability is not sent in browser navigation or forwarded by SSR. Rehearsal does not expose arbitrary PHP, SQL, or shell commands. Artisan discovery allows registered commands; [disable it and register explicit aliases](../guide/extending#restrict-artisan-and-scheduled-work) when tests need only a small set.

## Browser sessions and time

`laravel.login({ id })` logs an existing user in through Laravel and sets the session on the current browser context. To test two roles in one attempt, use a second context and pass it to `laravel.login({ id }, context)`; both browsers see the same attempt database but keep their own sessions.

`laravel.travel("2034-01-02T12:00:00.000Z")` changes Laravel's attempt clock and fixes the Playwright clock for pages managed by the fixture. Pass a Date or an ISO string with an explicit offset to avoid local-time ambiguity.

See [service integrations](../guide/services) for what gets isolated and [SSR](../guide/ssr) for how separate frontends keep requests in the same attempt.
