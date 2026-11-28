import { test as base, type APIRequestContext } from "@playwright/test";
import { managedBrowsers, type NewBrowser } from "./browser.js";
import { Laravel, startAttempt } from "./client.js";
import { bindOperations, type BoundOperations, type OperationDefinitions } from "./extensions.js";
import { NuxtFakes } from "./nuxt-fakes.js";
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

export const test = base.extend<{ laravel: Laravel; newBrowser: NewBrowser; rehearsalUserEndpoint: string }, WorkerOptions>({
  rehearsalUserEndpoint: ["/api/user", { option: true }],
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
  laravel: [async ({ rehearsalRun, baseURL, rehearsalUserEndpoint }, use, testInfo) => {
    const { request, origin, capability, slot } = rehearsalRun;
    const attempt = await startAttempt(request, origin, capability, slot);
    await testInfo.attach("rehearsal-reset", { body: JSON.stringify(attempt.resetMs), contentType: "application/json" });
    const laravel = new Laravel(origin, request, capability, attempt.marker, baseURL ? new URL(baseURL).origin : origin, attempt.client, rehearsalUserEndpoint);
    try {
      await use(laravel);
    } finally {
      await laravel.disposeFakes();
    }
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
    await use(laravel.reverb);
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

export const nuxtTest = base.extend<{ nuxt: NuxtFakes; nuxtURL?: string }>({
  nuxtURL: [undefined, { option: true }],
  nuxt: async ({ page, baseURL, nuxtURL }, use) => {
    const frontend = nuxtURL ?? baseURL;
    if (!frontend) throw new Error("Rehearsal Nuxt fakes require baseURL or nuxtURL.");
    const nuxt = new NuxtFakes(page, frontend);
    try {
      await use(nuxt);
    } finally {
      await nuxt.close();
    }
  },
});

export { passthrough, type NuxtFakeHandler, type NuxtFakePattern, type NuxtFakeResponse, type NuxtFakeRule, type NuxtFakeRules, type NuxtHold, type NuxtNavigationOptions } from "./nuxt-fakes.js";
export { expect } from "@playwright/test";
export type { NewBrowser, NewBrowserOptions, NewBrowserSession } from "./browser.js";
export { Laravel, type FactoryCall, type FactoryDefinition, type TypesenseClientConfig } from "./client.js";
export type { FactoryAttributes, FactoryBuilder, FactoryCountBuilder, FactoryModel, FactoryModelRef, FactoryName, FakeRouteKey, FakeTarget, ModelColumn, ModelQuery, ModelResult, ModelWhereOperator } from "./schema.js";
export type { FakeCallback, FakeResponse, FakeRoute, RouteTarget, RouteResponse } from "./routes.js";
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
