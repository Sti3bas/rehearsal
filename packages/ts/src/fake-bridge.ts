import { randomBytes } from "node:crypto";
import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";

export const fakeBridgeHeader = "x-rehearsal-fake";
export const fakeBridgePath = "/_rehearsal/fake";

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
    this.server.closeAllConnections();
    await new Promise<void>((resolve) => this.server!.close(() => resolve()));
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
            if (size > 2_000_000) {
              outgoing.writeHead(413).end();
              return;
            }
            chunks.push(chunk);
          }
          const { id, url, method, headers, body } = JSON.parse(Buffer.concat(chunks).toString()) as WireRequest & { id: string };
          const handler = this.handlers.get(id);
          if (!handler) {
            outgoing.writeHead(404).end();
            return;
          }
          const request = new Request(url, { method, headers, body: body === undefined ? undefined : Buffer.from(body, "base64") });
          const value = await handler(request);
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
