import type { Laravel } from "./client.js";

export interface DrainOptions {
  queues?: string[];
  maxJobs?: number;
}

export interface JobFailure {
  job: string;
  exception: string;
}

export interface FailedJob extends JobFailure {
  id: string;
  queue: string;
  failedAt: string;
}

export interface DrainResult {
  code: number;
  removed: number;
  pending: number;
  processed: number;
  failed: number;
  failures: JobFailure[];
}

export class Queue {
  constructor(private laravel: Laravel) {}

  drain(options: DrainOptions = {}) {
    return this.laravel.operation<DrainResult>("queue.drain", { ...options });
  }

  failed(options: Pick<DrainOptions, "queues"> = {}) {
    return this.laravel.operation<FailedJob[]>("queue.failed", options);
  }

  async drainUntil(assertion: () => unknown, options: DrainOptions & { timeout?: number; interval?: number } = {}) {
    const { timeout = 10_000, interval = 100, ...drainOptions } = options;
    const deadline = performance.now() + timeout;
    let lastError: unknown;
    while (performance.now() < deadline) {
      const result = await this.drain(drainOptions);
      if (result.code !== 0 || result.failed) {
        const details = result.failures.map(({ job, exception }) => `${job}: ${exception}`).join(", ");
        throw new Error(`Rehearsal queue drain failed (exit ${result.code}, ${result.failed} failed jobs)${details ? ": " + details : ""}.`);
      }
      if (performance.now() >= deadline) break;
      try {
        await assertion();
      } catch (error) {
        lastError = error;
        const remaining = deadline - performance.now();
        if (remaining <= 0) break;
        await new Promise((resolve) => setTimeout(resolve, Math.min(interval, remaining)));
        continue;
      }
      if (performance.now() < deadline) return result;
      break;
    }
    throw new Error(`Rehearsal queue drain timed out after ${timeout} ms.`, { cause: lastError });
  }
}
