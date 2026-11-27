import { tagModel } from "./factory.js";
import type { FactoryName, ModelQuery, ModelWhereOperator } from "./schema.js";

type Row = Record<string, unknown>;

interface WhereClause {
  column: string;
  operator: ModelWhereOperator | "in" | "not_in" | "null" | "not_null";
  value?: unknown;
}

interface OrderClause {
  column: string;
  direction: "asc" | "desc";
}

interface QueryState {
  model: string;
  where: readonly WhereClause[];
  orderBy: readonly OrderClause[];
  limit?: number;
}

type SendQuery = <T = unknown>(payload: Record<string, unknown>) => Promise<T>;

function encodePayload(state: QueryState, extra: Record<string, unknown>): Record<string, unknown> {
  return {
    model: state.model,
    ...(state.where.length ? { where: state.where } : {}),
    ...(state.orderBy.length ? { orderBy: state.orderBy } : {}),
    ...(state.limit !== undefined ? { limit: state.limit } : {}),
    ...extra,
  };
}

function makeQuery<K extends FactoryName>(send: SendQuery, state: QueryState): ModelQuery<K> {
  return {
    where(columnOrAttrs: string | Record<string, unknown>, operatorOrValue?: unknown, maybeValue?: unknown) {
      if (typeof columnOrAttrs === "object" && columnOrAttrs !== null) {
        const clauses: WhereClause[] = Object.entries(columnOrAttrs).map(([column, value]) => {
          if (value === undefined) {
            throw new TypeError(`Model query where(${column}) received undefined; pass null or use whereNull().`);
          }
          return value === null ? { column, operator: "null" } : { column, operator: "=", value };
        });
        return makeQuery<K>(send, { ...state, where: [...state.where, ...clauses] });
      }
      if (arguments.length >= 3) {
        if (maybeValue === undefined) {
          throw new TypeError(`Model query where(${columnOrAttrs}) received undefined; pass null or use whereNull().`);
        }
        return makeQuery<K>(send, {
          ...state,
          where: [...state.where, { column: columnOrAttrs, operator: operatorOrValue as ModelWhereOperator, value: maybeValue }],
        });
      }
      if (operatorOrValue === undefined) {
        throw new TypeError(`Model query where(${columnOrAttrs}) received undefined; pass null or use whereNull().`);
      }
      const clause: WhereClause = operatorOrValue === null
        ? { column: columnOrAttrs, operator: "null" }
        : { column: columnOrAttrs, operator: "=", value: operatorOrValue };
      return makeQuery<K>(send, { ...state, where: [...state.where, clause] });
    },
    whereIn(column: string, values: readonly unknown[]) {
      return makeQuery<K>(send, { ...state, where: [...state.where, { column, operator: "in", value: [...values] }] });
    },
    whereNotIn(column: string, values: readonly unknown[]) {
      return makeQuery<K>(send, { ...state, where: [...state.where, { column, operator: "not_in", value: [...values] }] });
    },
    whereNull(column: string) {
      return makeQuery<K>(send, { ...state, where: [...state.where, { column, operator: "null" }] });
    },
    whereNotNull(column: string) {
      return makeQuery<K>(send, { ...state, where: [...state.where, { column, operator: "not_null" }] });
    },
    orderBy(column: string, direction: "asc" | "desc" = "asc") {
      return makeQuery<K>(send, { ...state, orderBy: [...state.orderBy, { column, direction }] });
    },
    limit(n: number) {
      return makeQuery<K>(send, { ...state, limit: n });
    },
    async first() {
      const row = await send<Row | null>(encodePayload(state, { action: "first" }));
      return row ? tagModel(row, state.model) : null;
    },
    async firstOrFail() {
      const row = await send<Row>(encodePayload(state, { action: "firstOrFail" }));
      return tagModel(row, state.model);
    },
    async get() {
      const rows = await send<Row[]>(encodePayload(state, { action: "get" }));
      return tagModel(rows, state.model);
    },
    pluck(column: string) {
      return send(encodePayload(state, { action: "pluck", column }));
    },
    value(column: string) {
      return send(encodePayload(state, { action: "value", column }));
    },
    count() {
      return send<number>(encodePayload(state, { action: "count" }));
    },
    exists() {
      return send<boolean>(encodePayload(state, { action: "exists" }));
    },
    update(values: Record<string, unknown>) {
      return send<number>(encodePayload(state, { action: "update", values }));
    },
    delete() {
      return send<number>(encodePayload(state, { action: "delete" }));
    },
  } as ModelQuery<K>;
}

export function createModelQuery<K extends FactoryName>(send: SendQuery, model: K): ModelQuery<K> {
  return makeQuery<K>(send, { model, where: [], orderBy: [] });
}
