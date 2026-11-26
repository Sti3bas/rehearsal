import { expect } from "@playwright/test";
import type { Laravel } from "./client.js";

export interface MailSummary {
  id: string;
  subject: string;
}

export interface MailMessage extends MailSummary {
  html: string;
  text: string;
}

export interface MailFilter {
  subject?: string | RegExp;
  timeout?: number;
}

function matches(value: string, query?: string | RegExp) {
  return query === undefined || (typeof query === "string" ? value === query : new RegExp(query).test(value));
}

function decode(value: string) {
  const named: Record<string, string> = { amp: "&", quot: '"', apos: "'", lt: "<", gt: ">", nbsp: "\u00a0" };
  return value.replace(/&(#x[0-9a-f]+|#\d+|amp|quot|apos|lt|gt|nbsp);/gi, (original, entity: string) => {
    if (!entity.startsWith("#")) return named[entity.toLowerCase()] ?? original;
    const hex = entity[1].toLowerCase() === "x";
    const codePoint = Number.parseInt(entity.slice(hex ? 2 : 1), hex ? 16 : 10);
    return codePoint <= 0x10ffff ? String.fromCodePoint(codePoint) : original;
  });
}

export class Mailbox {
  constructor(private laravel: Laravel, private recipient: string) {}

  all() {
    return this.laravel.operation<MailSummary[]>("mail.inbox", { recipient: this.recipient });
  }

  async latest({ subject, timeout = 10_000 }: MailFilter = {}) {
    let match: MailSummary | undefined;
    await expect.poll(async () => {
      match = (await this.all()).find((message) => matches(message.subject, subject));
      return match?.id;
    }, { timeout, message: "waiting for mail to " + this.recipient }).toBeDefined();
    return this.laravel.operation<MailMessage>("mail.inbox", { recipient: this.recipient, id: match!.id });
  }

  async expectReceived(subject?: string | RegExp, options: { timeout?: number } = {}) {
    await this.latest({ subject, ...options });
  }

  async link(text: string | RegExp, options?: MailFilter) {
    const message = await this.latest(options);
    const links = [...message.html.matchAll(/<a\b[^>]*?\shref\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))[^>]*>([\s\S]*?)<\/a\s*>/gi)];
    for (const link of links) {
      if (matches(decode(link[4].replace(/<[^>]*>/g, "")).trim(), text)) {
        return decode(link[1] ?? link[2] ?? link[3]);
      }
    }
    throw new Error(`No mail link matching ${text} in "${message.subject}".`);
  }
}
