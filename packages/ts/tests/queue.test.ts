import { afterEach, expect, it, vi } from "vitest";
import type { Laravel } from "../src/client.js";
import { Queue } from "../src/queue.js";

afterEach(() => vi.useRealTimers());

function queueFixture() {
  vi.useFakeTimers({ toFake: ["performance", "setTimeout"] });
  const operation = vi.fn().mockResolvedValue({ code: 0, failed: 0, failures: [] });
  return { queue: new Queue({ operation } as unknown as Laravel), operation };
}

it("does not start another drain when the retry wait reaches the deadline", async () => {
  const { queue, operation } = queueFixture();
  const pending = new Error("Not ready");
  const assertion = vi.fn().mockImplementationOnce(() => { throw pending; });
  const finished = queue.drainUntil(assertion, { timeout: 10, interval: 100 });
  const rejected = expect(finished).rejects.toMatchObject({ message: "Rehearsal queue drain timed out after 10 ms.", cause: pending });
  await vi.advanceTimersByTimeAsync(10);
  await rejected;
  expect(operation).toHaveBeenCalledTimes(1);
  expect(assertion).toHaveBeenCalledTimes(1);
});

it("does not accept an assertion that completes after the deadline", async () => {
  const { queue, operation } = queueFixture();
  const finished = queue.drainUntil(() => new Promise((resolve) => setTimeout(resolve, 20)), { timeout: 10 });
  const rejected = expect(finished).rejects.toThrow("timed out after 10 ms");
  await vi.advanceTimersByTimeAsync(20);
  await rejected;
  expect(operation).toHaveBeenCalledTimes(1);
});
