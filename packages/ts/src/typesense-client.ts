import { expect, type APIRequestContext } from "@playwright/test";
import type { TypesenseClientConfig } from "./client.js";

export class Typesense implements TypesenseClientConfig {
  #request: APIRequestContext;

  constructor(
    readonly origin: string,
    readonly collectionPrefix: string,
    readonly searchKey: string,
    request: APIRequestContext,
  ) {
    this.#request = request;
  }

  collection(collection: string) {
    const name = this.collectionPrefix + collection;
    const searchPath = "/collections/" + encodeURIComponent(name) + "/documents/search";
    return {
      name, searchPath,
      waitForIndexed: async (count: number, { timeout = 30_000 }: { timeout?: number } = {}) => {
        await expect.poll(async () => {
          const response = await this.#request.get(this.origin + searchPath, {
            headers: { "X-Typesense-Api-Key": this.searchKey },
            params: { q: "*", per_page: "0" },
            maxRedirects: 0,
          });
          try {
            return response.ok() ? (await response.json()).found as number : -1;
          } finally {
            await response.dispose();
          }
        }, { timeout, intervals: [500, 1000, 2000], message: "waiting for Typesense collection " + name }).toBeGreaterThanOrEqual(count);
      },
    };
  }
}
