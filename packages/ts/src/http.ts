export interface HttpFakeResponse {
  status?: number;
  json?: unknown;
  body?: string;
  headers?: Record<string, string>;
  networkError?: boolean;
}

export type HttpFakeRule = HttpFakeResponse | { method?: string; responses: HttpFakeResponse[] };
export type HttpFakeRules = Record<string, HttpFakeRule>;

export function encodeHttpRules(rules: HttpFakeRules) {
  return Object.entries(rules).map(([url, rule]) => ({
    url,
    method: "responses" in rule ? rule.method?.toUpperCase() : undefined,
    responses: ("responses" in rule ? rule.responses : [rule]).map(({ json, ...response }) => {
      if (json === undefined) return response;
      const jsonBody = JSON.stringify(json);
      if (jsonBody === undefined) throw new Error("Rehearsal HTTP fakes need a JSON-serializable body.");
      return { ...response, jsonBody };
    }),
  }));
}
