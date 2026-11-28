# Mailpit <Badge type="info" text="Optional" />

Mailpit catches mail sent by the real Laravel mailer. Rehearsal uses a tag for each attempt so a spec can inspect rendered HTML, text, and links without reading another worker's mail. Reset and cleanup delete messages with Rehearsal's own tags.

## Configure

Start a disposable Mailpit instance with its API and SMTP listener on loopback. With [integration discovery](../concepts/discovery) enabled, a valid loopback API origin activates Mailpit:

```dotenv [.env.rehearsal]
REHEARSAL_MAILPIT_API=http://127.0.0.1:8025
REHEARSAL_MAILPIT_SMTP_PORT=1025
```

With discovery off, add `'mailpit' => \Sti3bas\Rehearsal\Mailpit::class` to the existing integration map. See the [Mailpit config keys](../guide/configuration#optional-services) for environment fallbacks.

::: warning The integration sets the default mailer
If the app chooses a named mailer explicitly, make sure that mailer cannot send to live SMTP in the test environment.
:::

## In a test

After triggering an email in your app, wait for it and follow a rendered link:

```ts
const inbox = laravel.mailbox("buyer@example.test");
await inbox.expectReceived(/verify/i);
const verifyURL = await inbox.link("Verify address", { subject: /verify/i });
await page.goto(verifyURL);
```

`inbox.latest({ subject })` waits for a matching message and returns its HTML and text. `inbox.all()` lists recent summaries without waiting. If the app queues mail, run [`laravel.queue.drain()`](../concepts/background-work) before looking for the message.
