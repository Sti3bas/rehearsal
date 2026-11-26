import type { TypesenseClientConfig } from "./client.js";

const collectionOptions = [
  "collectionSpecificSearchParameters", "collectionSpecificFacetByOptions",
  "collectionSpecificFilterByOptions", "collectionSpecificSortByOptions",
] as const;

export function typesenseInstantSearch(credentials?: TypesenseClientConfig) {
  const indexName = (collection: string) => credentials ? credentials.collectionPrefix + collection : collection;
  return {
    indexName,
    configure<T extends { server: object } & Partial<Record<typeof collectionOptions[number], object>>>(options: T): T {
      if (!credentials) return options;
      const url = new URL(credentials.origin);
      const collections = Object.fromEntries(collectionOptions.flatMap((name) => {
        const settings = options[name];
        return settings ? [[name, Object.fromEntries(
          Object.entries(settings).map(([collection, value]) => [indexName(collection), value]),
        )]] : [];
      }));
      return {
        ...options,
        ...collections,
        server: {
          ...options.server,
          apiKey: credentials.searchKey,
          nodes: [{ host: url.hostname, port: Number(url.port || (url.protocol === "https:" ? 443 : 80)), protocol: url.protocol.slice(0, -1) }],
          nearestNode: undefined,
        },
      };
    },
  };
}
