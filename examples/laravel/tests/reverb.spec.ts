import { mkdir, readdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { expect, reverbTest as test, type ReverbClientConfig } from "@sti3bas/rehearsal";

declare global {
  var __REHEARSAL_REVERB__: ReverbClientConfig;
  var rehearsalEvents: unknown[];
}

test("injects the attempt's Reverb settings into a managed secondary browser", async ({ laravel, newBrowser }) => {
  const { page } = await newBrowser({ page: true });
  await page.goto("/");
  const config = laravel.integrationMetadata("reverb");
  expect(config).toBeDefined();
  expect(await page.evaluate(() => globalThis.__REHEARSAL_REVERB__)).toEqual(config);
});

for (const name of ["Ada", "Grace", "Linus", "Margaret"]) {
  test("isolates identical Reverb channels for " + name, async ({ reverb, page }, info) => {
    expect(await reverb.subscribed("probe")).toBe(false);
    await page.goto("/");
    await page.evaluate(() => new Promise<void>((ready, fail) => {
      const { host, port, key, scheme } = globalThis.__REHEARSAL_REVERB__;
      const socket = new WebSocket(`${scheme}://${host}:${port}/app/${key}?protocol=7&client=js&version=8.4.0`);
      globalThis.rehearsalEvents = [];
      socket.onerror = () => fail(new Error("Reverb WebSocket error"));
      socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (message.event === "pusher:connection_established") {
          socket.send(JSON.stringify({ event: "pusher:subscribe", data: { channel: "probe" } }));
        } else if (message.event === "pusher_internal:subscription_succeeded") {
          ready();
        } else if (message.event === "ping") {
          globalThis.rehearsalEvents.push(JSON.parse(message.data));
        } else if (message.event === "pusher:error") {
          fail(new Error("Reverb refused the subscription"));
        }
      };
    }));
    const barrier = resolve(info.project.outputDir, "reverb-barrier");
    await mkdir(barrier, { recursive: true });
    await writeFile(resolve(barrier, "open-" + info.parallelIndex), name);
    await expect.poll(async () => (await readdir(barrier)).filter((file) => file.startsWith("open-")).length).toBe(4);
    await reverb.waitForSubscriber("probe");
    const payload = { value: name, empty: {}, numeric: { 0: name }, list: [name] };
    await reverb.broadcast("probe", "ping", payload);
    await writeFile(resolve(barrier, "sent-" + info.parallelIndex), name);
    await expect.poll(async () => (await readdir(barrier)).filter((file) => file.startsWith("sent-")).length).toBe(4);
    await expect.poll(() => page.evaluate(() => globalThis.rehearsalEvents)).toEqual([payload]);
  });
}
