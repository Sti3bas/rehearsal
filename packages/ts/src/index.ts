import { test as base, type APIRequestContext } from "@playwright/test";
import { managedBrowsers, type NewBrowser } from "./browser.js";
import { Laravel, startAttempt } from "./client.js";
import { bindOperations, type BoundOperations, type OperationDefinitions } from "./extensions.js";
import { injectReverb, Reverb } from "./reverb.js";
import { loopback, managedLaunch, protocol } from "./transport.js";

export interface RehearsalSchema {}

interface RunConnection {
  origin: string;
  capability: string;
  request: APIRequestContext;
  slot: number;
}

interface WorkerOptions {
  laravelURL?: string;
  rehearsalRoot: string;
  rehearsalCapability?: string;
  rehearsalRun: RunConnection;
}

export const test = base.extend<{ laravel: Laravel; newBrowser: NewBrowser }, WorkerOptions>({
  laravelURL: [undefined, { option: true, scope: "worker" }],
  rehearsalRoot: [process.cwd(), { option: true, scope: "worker" }],
  rehearsalCapability: [undefined, { option: true, scope: "worker" }],
  serviceWorkers: "block",
  rehearsalRun: [async ({ playwright, laravelURL, rehearsalRoot, rehearsalCapability }, use, workerInfo) => {
    const configured = laravelURL ?? workerInfo.project.use.baseURL;
    if (!configured) throw new Error("Rehearsal requires baseURL or laravelURL.");
    const url = new URL(configured);
    if (!loopback(url)) throw new Error("Rehearsal v1 requires a loopback Laravel origin.");
    const request = await playwright.request.newContext();
    try {
      const launch = rehearsalCapability ? undefined : await managedLaunch(rehearsalRoot, Number(url.port || 80));
      const ready = await request.get(url.origin + "/_rehearsal/ready", { maxRedirects: 0 });
      if (!ready.ok()) throw new Error("Rehearsal server not ready (HTTP " + ready.status() + ").");
      const info = await ready.json();
      if (info.protocol !== protocol || (launch && info.run !== launch.run)) {
        throw new Error("Rehearsal run/protocol mismatch.");
      }
      await use({
        origin: url.origin,
        capability: rehearsalCapability ?? launch!.capability,
        request,
        slot: workerInfo.parallelIndex + 1,
      });
    } finally {
      await request.dispose();
    }
  }, { scope: "worker" }],
  laravel: [async ({ rehearsalRun, baseURL }, use, testInfo) => {
    const { request, origin, capability, slot } = rehearsalRun;
    const attempt = await startAttempt(request, origin, capability, slot);
    await testInfo.attach("rehearsal-reset", { body: JSON.stringify(attempt.resetMs), contentType: "application/json" });
    await use(new Laravel(origin, request, capability, attempt.marker, baseURL ? new URL(baseURL).origin : origin, attempt.client));
  }, { auto: true }],
  context: async ({ context, laravel }, use) => {
    await laravel.bindContext(context);
    await use(context);
  },
  page: async ({ page, laravel }, use) => {
    await laravel.preparePage(page);
    await use(page);
  },
  newBrowser: ({ browser, laravel }, use) => managedBrowsers(browser, laravel, use),
});

export const reverbTest = test.extend<{ reverb: Reverb }>({
  reverb: async ({ laravel }, use) => {
    await use(new Reverb(laravel));
  },
  context: async ({ context, laravel, baseURL }, use) => {
    if (!baseURL) throw new Error("The Rehearsal Reverb plugin requires a frontend baseURL.");
    await injectReverb(context, baseURL, laravel.integrationMetadata("reverb"));
    await use(context);
  },
  newBrowser: ({ browser, laravel, baseURL }, use) =>
    managedBrowsers(browser, laravel, use, async (context, options) => {
      const frontend = options.context?.baseURL ?? baseURL;
      if (!frontend) throw new Error("The Rehearsal Reverb plugin requires a frontend baseURL.");
      await injectReverb(context, frontend, laravel.integrationMetadata("reverb"));
    }),
});

export { expect } from "@playwright/test";
export type { NewBrowser, NewBrowserOptions, NewBrowserSession } from "./browser.js";
export { Laravel, type FactoryCall, type FactoryDefinition, type TypesenseClientConfig } from "./client.js";
export type { FactoryAttributes, FactoryBuilder, FactoryCountBuilder, FactoryModel, FactoryModelRef, FactoryName, ModelColumn, ModelQuery, ModelResult, ModelWhereOperator } from "./schema.js";
export type { RouteTarget, RouteResponse } from "./routes.js";
export type { Mailbox, MailFilter, MailMessage, MailSummary } from "./mail.js";
export type { DrainOptions, DrainResult, FailedJob } from "./queue.js";
export type { HttpFakeResponse, HttpFakeRule, HttpFakeRules } from "./http.js";
export { laravelServer } from "./server.js";
export { generateRehearsalTypes } from "./types.js";
export { injectReverb, type ReverbClientConfig } from "./reverb.js";
export function defineRehearsal<T extends OperationDefinitions>({ operations }: { operations: T }) {
  return test.extend<{ operations: BoundOperations<T> }>({
    operations: async ({ laravel }, use) => {
      await use(bindOperations(operations, laravel));
    },
  });
}

export { defineOperation, type BoundOperations, type Operation } from "./extensions.js";
