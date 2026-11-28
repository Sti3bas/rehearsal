import { randomBytes } from "node:crypto";
import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";

export const fakeBridgeHeader = "x-rehearsal-fake";
export const fakeBridgePath = "/_rehearsal/fake";

export function isLoopbackPeer(address?: string) {
  return address === "127.0.0.1" || address === "::1" || address === "::ffff:127.0.0.1";
}

export interface WireRequest {
  url: string;
  method: string;
  headers: [string, string][];
  body?: string;
}

export type FakeHandler = (request: Request) => unknown | Promise<unknown>;

export class FakeBridge {
  private token = randomBytes(32).toString("hex");
  private handlers = new Map<string, FakeHandler>();
  private active = new Set<Promise<unknown>>();
  private server?: Server;
  private ready?: Promise<number>;

  async register(handler: FakeHandler) {
    const id = randomBytes(16).toString("hex");
    this.handlers.set(id, handler);
    try {
      return { port: await (this.ready ??= this.listen()), token: this.token, id };
    } catch (error) {
      this.handlers.delete(id);
      throw error;
    }
  }

  clear() {
    this.handlers.clear();
  }

  async close() {
    this.clear();
    if (!this.server) return;
    const server = this.server;
    const closed = new Promise<void>((resolve) => server.close(() => resolve()));
    server.closeAllConnections();
    let timer: ReturnType<typeof setTimeout> | undefined;
    try {
      await Promise.race([
        Promise.all([closed, Promise.allSettled(this.active)]),
        new Promise<never>((_, reject) => {
          timer = setTimeout(() => reject(new Error("Rehearsal fake callbacks did not stop after their requests were cancelled.")), 1_000);
        }),
      ]);
    } finally {
      clearTimeout(timer);
    }
  }

  private listen() {
    return new Promise<number>((resolve, reject) => {
      this.server = createServer(async (incoming, outgoing) => {
        if (incoming.method !== "POST" || incoming.url !== fakeBridgePath || incoming.headers[fakeBridgeHeader] !== this.token) {
          outgoing.writeHead(404).end();
          return;
        }
        try {
          const chunks: Buffer[] = [];
          let size = 0;
          for await (const chunk of incoming) {
            size += chunk.length;
            if (size > 32_000_000) {
              outgoing.writeHead(413).end();
              return;
            }
            chunks.push(chunk);
          }
          const { id, url, method, headers, body } = JSON.parse(Buffer.concat(chunks).toString()) as WireRequest & { id: string };
          const handler = this.handlers.get(id);
          if (!handler) {
            outgoing.writeHead(200, { "content-type": "application/json" }).end("null");
            return;
          }
          const controller = new AbortController();
          outgoing.once("close", () => { if (!outgoing.writableFinished) controller.abort(); });
          const request = new Request(url, { method, headers, signal: controller.signal, body: body === undefined ? undefined : Buffer.from(body, "base64") });
          const handlerResult = Promise.resolve().then(() => handler(request));
          this.active.add(handlerResult);
          const value = await handlerResult.finally(() => this.active.delete(handlerResult));
          const json = JSON.stringify(value ?? null);
          if (Buffer.byteLength(json) > 2_000_000) {
            outgoing.writeHead(413).end();
            return;
          }
          outgoing.writeHead(200, { "content-type": "application/json" }).end(json);
        } catch {
          if (!outgoing.writableEnded) outgoing.writeHead(500).end();
        }
      });
      this.server.once("error", reject).listen(0, "127.0.0.1", () => resolve((this.server!.address() as AddressInfo).port));
    });
  }
}
