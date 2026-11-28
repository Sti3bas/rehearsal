import type { Browser, BrowserContext, BrowserContextOptions, Page } from "@playwright/test";
import type { Laravel } from "./client.js";

export interface NewBrowserOptions {
  user?: { id: number | string };
  context?: BrowserContextOptions;
  page?: boolean;
}

export interface NewBrowserSession {
  context: BrowserContext;
  page?: Page;
}

export interface NewBrowser {
  (options: NewBrowserOptions & { page: true }): Promise<NewBrowserSession & { page: Page }>;
  (options?: NewBrowserOptions): Promise<NewBrowserSession>;
}

export async function managedBrowsers(
  browser: Browser,
  laravel: Laravel,
  use: (open: NewBrowser) => Promise<void>,
  initialize?: (context: BrowserContext, options: NewBrowserOptions) => Promise<void>,
) {
  const contexts = new Set<BrowserContext>();

  async function open(options: NewBrowserOptions & { page: true }): Promise<NewBrowserSession & { page: Page }>;
  async function open(options?: NewBrowserOptions): Promise<NewBrowserSession>;
  async function open(options: NewBrowserOptions = {}): Promise<NewBrowserSession> {
    const context = await browser.newContext(options.context);
    contexts.add(context);
    context.once("close", () => contexts.delete(context));

    try {
      await laravel.bindContext(context, { primary: false });
      await initialize?.(context, options);
      if (options.user) await laravel.login(options.user, context);
      if (!options.page) return { context };

      const page = await context.newPage();
      await laravel.preparePage(page);
      return { context, page };
    } catch (error) {
      await context.close().catch(() => {});
      throw error;
    }
  }

  try {
    await use(open);
  } finally {
    await Promise.all([...contexts].map((context) => context.close()));
  }
}
