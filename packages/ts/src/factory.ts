import type { FactoryCall, FactoryDefinition } from "./client.js";
import { rehearsalModel } from "./schema.js";

type Row = Record<string, unknown>;
type FactoryResult = Row | Row[];
type SendFactories = (items: readonly FactoryDefinition[]) => Promise<FactoryResult[]>;

export function tagModel<T>(value: T, alias: string): T {
  if (Array.isArray(value)) {
    for (const item of value) {
      if (item && typeof item === "object") {
        Object.defineProperty(item, rehearsalModel, { value: alias, enumerable: false, configurable: true });
      }
    }
  } else if (value && typeof value === "object") {
    Object.defineProperty(value, rehearsalModel, { value: alias, enumerable: false, configurable: true });
  }
  return value;
}

export function serializeFactoryArg(arg: unknown): unknown {
  if (arg instanceof Date) {
    return arg.toISOString();
  }
  if (arg && typeof arg === "object") {
    const tagged = (arg as Record<PropertyKey, unknown>)[rehearsalModel];
    if (typeof tagged === "string" && "id" in arg) {
      return { $rehearsalModel: tagged, id: (arg as { id: number | string }).id };
    }
    if (Array.isArray(arg)) {
      return arg.map(serializeFactoryArg);
    }
  }
  return arg;
}

function serializeForTarget(target: unknown): Record<string, unknown> {
  if (target && typeof target === "object") {
    const tagged = (target as Record<PropertyKey, unknown>)[rehearsalModel];
    if (typeof tagged === "string" && "id" in target) {
      return { $rehearsalModel: tagged, id: (target as { id: number | string }).id };
    }
    if ("id" in target) {
      return { id: (target as { id: number | string }).id };
    }
  }
  throw new Error("Rehearsal factory for() requires a model with an id.");
}

function mergeCallAttrs(calls: readonly FactoryCall[]): Row {
  const merged: Row = {};
  for (const call of calls) {
    if ("attrs" in call && call.attrs) {
      Object.assign(merged, call.attrs);
    }
  }
  return merged;
}

export function createFactoryBuilder(
  send: SendFactories,
  model: string,
  calls: readonly FactoryCall[] = [],
  count?: number,
): unknown {
  const buildItem = (attrs?: Row, itemCount?: number): FactoryDefinition => ({
    model,
    ...(calls.length > 0 ? { calls: [...calls] } : {}),
    ...(attrs && Object.keys(attrs).length > 0 ? { attrs } : {}),
    ...(itemCount !== undefined ? { count: itemCount } : {}),
  });

  return new Proxy(Object.create(null), {
    get(_target, prop) {
      if (typeof prop === "symbol" || prop === "then" || prop === "catch" || prop === "finally" || prop === "toJSON") {
        return undefined;
      }
      if (prop === "state") {
        return (attrs: Row) => createFactoryBuilder(send, model, [...calls, { attrs }], count);
      }
      if (prop === "for") {
        return (target: unknown, relation?: string) => createFactoryBuilder(
          send,
          model,
          [...calls, { for: serializeForTarget(target), ...(relation !== undefined ? { relation } : {}) }],
          count,
        );
      }
      if (prop === "count") {
        return (n: number) => createFactoryBuilder(send, model, calls, n);
      }
      if (prop === "create") {
        return async (overrides?: Row | ((attrs: Row, index: number) => Row)) => {
          if (count !== undefined) {
            if (typeof overrides === "function") {
              if (count <= 0) return [];
              const baseAttrs = mergeCallAttrs(calls);
              const items = Array.from({ length: count }, (_, index) => buildItem(overrides(baseAttrs, index)));
              return send(items);
            }
            const results = await send([buildItem(overrides, count)]);
            return results[0];
          }
          const results = await send([buildItem(typeof overrides === "function" ? undefined : overrides)]);
          return results[0];
        };
      }
      if (prop === "createMany") {
        return async (overridesList: readonly Row[]) => {
          if (overridesList.length === 0) return [];
          const items = overridesList.map((overrides) => buildItem(overrides));
          return send(items);
        };
      }
      return (...args: unknown[]) => createFactoryBuilder(
        send,
        model,
        [...calls, { state: prop, args: args.map(serializeFactoryArg) }],
        count,
      );
    },
  });
}
