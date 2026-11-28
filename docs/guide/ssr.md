# SSR and split frontends

This guide has moved to [Frontends](../frontends/). Start with [two-origin setup](../frontends/#use-two-origins-for-a-split-frontend) for split apps, or [Inertia](../frontends/inertia) for Laravel-served pages.

## Nuxt 4

See the [Nuxt guide](../frontends/nuxt) for SSR, Nitro, cookie forwarding, and Typesense InstantSearch. Use `nuxt.fake` for browser and SSR fetches, `laravel.fake` for requests that reach your Laravel routes, and `laravel.http.fake` for requests Laravel sends through its HTTP client. See [which fake to use](../concepts/mocking).
