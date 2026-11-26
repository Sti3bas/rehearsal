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
    const deadline = Date.now() + timeout;
    while (true) {
      const result = await this.drain(drainOptions);
      if (result.code !== 0 || result.failed) {
        const details = result.failures.map(({ job, exception }) => `${job}: ${exception}`).join(", ");
        throw new Error(`Rehearsal queue drain failed (exit ${result.code}, ${result.failed} failed jobs)${details ? ": " + details : ""}.`);
      }
      try {
        await assertion();
        return result;
      } catch (error) {
        const remaining = deadline - Date.now();
        if (remaining <= 0) throw error;
        await new Promise((resolve) => setTimeout(resolve, Math.min(interval, remaining)));
      }
    }
  }
}
