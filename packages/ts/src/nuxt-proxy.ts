import { Buffer } from "node:buffer";
import { HttpResponse } from "msw";
import { fakeBridgeHeader, fakeBridgePath, isLoopbackPeer, type WireRequest } from "./fake-bridge.js";
import { mockResponse, wait } from "./routes.js";
import { capabilityHeader, markerHeader } from "./transport.js";

export function nuxtProxyContext(request: { headers: Record<string, unknown>; socket: { remoteAddress?: string; readable?: boolean; writable?: boolean } }) {
  const header = request.headers[fakeBridgeHeader];
  delete request.headers[fakeBridgeHeader];
  const devWorkerSocket = process.env.NITRO_DEV_WORKER_ID && !request.socket.remoteAddress &&
    request.socket.readable && request.socket.writable;
  if ((!isLoopbackPeer(request.socket.remoteAddress) && !devWorkerSocket) || typeof header !== "string") return;
  const parts = /^(\d{1,5})\.([a-f0-9]{64})\.([a-f0-9]{32})$/.exec(header);
  if (!parts) return;
  const port = Number(parts[1]);
  if (port < 1 || port > 65535) return;
  return { port, token: parts[2], id: parts[3] };
}

export async function proxyNuxtFetch(
  fetch: typeof globalThis.fetch, context: NonNullable<ReturnType<typeof nuxtProxyContext>>, input: RequestInfo | URL, init?: RequestInit,
) {
  const request = new Request(input instanceof Request ? input.clone() : input, init);
  request.signal.throwIfAborted();
  const headers = new Headers(request.headers);
  headers.delete(fakeBridgeHeader);
  headers.delete(capabilityHeader);
  headers.delete(markerHeader);
  const wire: WireRequest & { id: string } = {
    id: context.id, url: request.url, method: request.method, headers: Array.from(headers),
    ...(request.body ? { body: Buffer.from(await request.clone().arrayBuffer()).toString("base64") } : {}),
  };
  const result = await fetch("http://127.0.0.1:" + context.port + fakeBridgePath, {
    method: "POST",
    headers: { [fakeBridgeHeader]: context.token, "content-type": "application/json" },
    body: JSON.stringify(wire),
    redirect: "error",
    signal: AbortSignal.any([request.signal, AbortSignal.timeout(10_000)]),
  });
  if (!result.ok) throw new Error("Rehearsal Nuxt fake handler failed (HTTP " + result.status + ").");
  const reply = await result.json() as Parameters<typeof mockResponse>[0] | null;
  if (!reply) return null;
  if (reply.delay) await wait(reply.delay, request.signal);
  const { body, ...response } = mockResponse(reply);
  return new HttpResponse(request.method === "HEAD" ? undefined : body, response);
}
