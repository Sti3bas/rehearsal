import { defineOperation, defineRehearsal, expect } from "@sti3bas/rehearsal";

const rehearsal = defineRehearsal({
  operations: { write: defineOperation<{ value: string }, null>("probe.write") },
});

const test = rehearsal.extend<{ writeProbe: (value: string) => Promise<null> }>({
  writeProbe: async ({ operations }, use) => {
    await use((value) => operations.write({ value }));
  },
});

for (const name of ["Ada", "Grace", "Linus", "Margaret"]) {
  test("composes an app fixture and operation for " + name, async ({ writeProbe, page }) => {
    await expect(writeProbe(name)).resolves.toBeNull();
    expect(await (await page.request.get("/state")).json()).toMatchObject({ disk: name });
  });
}
