import type { Reporter, TestCase, TestResult } from "@playwright/test/reporter";

export default class RehearsalReporter implements Reporter {
  private samples = new Map<string, number[]>();

  onTestEnd(_test: TestCase, result: TestResult) {
    for (const attachment of result.attachments) {
      if (attachment.name !== "rehearsal-reset" || !attachment.body) continue;
      let timings: unknown;
      try {
        timings = JSON.parse(attachment.body.toString());
      } catch {
        continue;
      }
      if (!timings || typeof timings !== "object") continue;
      for (const [name, ms] of Object.entries(timings)) {
        if (typeof ms !== "number" || !Number.isFinite(ms) || ms < 0) continue;
        const sample = this.samples.get(name) ?? [];
        sample.push(ms);
        this.samples.set(name, sample);
      }
    }
  }

  onEnd() {
    if (!this.samples.size) return;
    console.log("Rehearsal reset timing by integration (ms)");
    for (const [name, sample] of [...this.samples].sort(([a], [b]) => a.localeCompare(b))) {
      const mean = sample.reduce((sum, ms) => sum + ms, 0) / sample.length;
      const max = sample.reduce((largest, ms) => Math.max(largest, ms), 0);
      console.log(`  ${name}: mean ${mean.toFixed(2)}, max ${max.toFixed(2)}, attempts ${sample.length}`);
    }
  }
}
