import type { RehearsalSchema } from "./index.js";
import type { RouteTarget } from "./routes.js";

export const rehearsalModel: unique symbol = Symbol.for("rehearsal.model");

type Schema<K, Fallback> = K extends keyof RehearsalSchema ? RehearsalSchema[K] : Fallback;
type Row = Record<string, unknown>;
type FallbackStates = Record<string, readonly unknown[]>;
type Factories = Schema<"factories", Record<string, { attributes: Row; model: Row; states: FallbackStates; relations: Record<string, string> }>>;
type Operations = Schema<"operations", Record<string, { input: Row; output: unknown }>>;
type Artisan = Schema<"artisan", Record<string, Row>>;

export type FactoryName = keyof Factories & string;
export type FactoryAttributes<K extends FactoryName> = Factories[K]["attributes"];
export type ModelResult<K extends FactoryName> = K extends FactoryName
  ? Factories[K]["model"] & { readonly [rehearsalModel]?: K }
  : never;
export type FactoryModel<K extends FactoryName> = K extends FactoryName
  ? ModelResult<K> & (
    Factories[K] extends { factoryRequired: infer RequiredKeys }
      ? Required<Pick<Factories[K]["model"], Extract<RequiredKeys, keyof Factories[K]["model"]>>>
      : unknown
  )
  : never;
export type FactoryModelRef<K extends FactoryName = FactoryName> = { readonly id: number | string; readonly [rehearsalModel]?: K };
export type FactoryStateMap<K extends FactoryName> =
  [Factories[K]["states"]] extends [never]
    ? Record<never, []>
    : Factories[K]["states"] extends Record<string, readonly unknown[]>
      ? Factories[K]["states"]
      : Record<Factories[K]["states"] & string, []>;
export type FactoryState<K extends FactoryName> = keyof FactoryStateMap<K> & string;
export type FactoryStateCall<K extends FactoryName> =
  | FactoryState<K>
  | { [S in FactoryState<K>]: { name: S; args?: FactoryStateMap<K>[S] } }[FactoryState<K>];
export type FactoryOptions<K extends FactoryName> = { states?: readonly FactoryStateCall<K>[] };

interface FactoryBuilderBase<K extends FactoryName> {
  state(attrs: FactoryAttributes<K>): FactoryBuilder<K>;
  for(model: FactoryModelRef<FactoryName>, relation?: string): FactoryBuilder<K>;
  count(n: number): FactoryCountBuilder<K>;
  create(overrides?: FactoryAttributes<K>): Promise<FactoryModel<K>>;
  createMany<const O extends readonly FactoryAttributes<K>[]>(
    overrides: O,
  ): Promise<{ -readonly [I in keyof O]: FactoryModel<K> }>;
}

interface FactoryCountBuilderBase<K extends FactoryName> {
  state(attrs: FactoryAttributes<K>): FactoryCountBuilder<K>;
  for(model: FactoryModelRef<FactoryName>, relation?: string): FactoryCountBuilder<K>;
  create(
    overrides?: FactoryAttributes<K> | ((attrs: FactoryAttributes<K>, index: number) => FactoryAttributes<K>),
  ): Promise<FactoryModel<K>[]>;
}

export type FactoryBuilder<K extends FactoryName> = FactoryBuilderBase<K> & {
  [S in FactoryState<K>]: (...args: FactoryStateMap<K>[S]) => FactoryBuilder<K>;
};
export type FactoryCountBuilder<K extends FactoryName> = FactoryCountBuilderBase<K> & {
  [S in FactoryState<K>]: (...args: FactoryStateMap<K>[S]) => FactoryCountBuilder<K>;
};
export type ModelWhereOperator = "=" | "!=" | "<" | "<=" | ">" | ">=" | "like";
type ModelColumnNames<K extends FactoryName> = Factories[K] extends { modelColumns: infer Columns } ? Columns & string : string;
export type ModelColumn<K extends FactoryName> = keyof Factories[K]["model"] & ModelColumnNames<K>;
type ModelFilterKeys<K extends FactoryName> = Extract<keyof FactoryAttributes<K>, ModelColumnNames<K>>;
type ModelFilterValues<K extends FactoryName> = Factories[K] extends { modelFilters: infer Filters }
  ? Filters
  : { [C in ModelFilterKeys<K>]?: C extends keyof Factories[K]["model"] ? Factories[K]["model"][C] : FactoryAttributes<K>[C] };
type ModelFilters<K extends FactoryName> = [keyof ModelFilterValues<K>] extends [never]
  ? Record<string, never>
  : { [C in keyof ModelFilterValues<K>]?: ModelFilterValues<K>[C] | null };
export interface ModelQuery<K extends FactoryName> {
  where(attrs: ModelFilters<K>): ModelQuery<K>;
  where<C extends ModelColumn<K>>(column: C, value: Factories[K]["model"][C] | null): ModelQuery<K>;
  where<C extends ModelColumn<K>>(column: C, operator: ModelWhereOperator, value: unknown): ModelQuery<K>;
  whereIn<C extends ModelColumn<K>>(column: C, values: readonly Factories[K]["model"][C][]): ModelQuery<K>;
  whereNotIn<C extends ModelColumn<K>>(column: C, values: readonly Factories[K]["model"][C][]): ModelQuery<K>;
  whereNull(column: ModelColumn<K>): ModelQuery<K>;
  whereNotNull(column: ModelColumn<K>): ModelQuery<K>;
  orderBy(column: ModelColumn<K>, direction?: "asc" | "desc"): ModelQuery<K>;
  limit(n: number): ModelQuery<K>;
  first(): Promise<ModelResult<K> | null>;
  firstOrFail(): Promise<ModelResult<K>>;
  get(): Promise<ModelResult<K>[]>;
  pluck<C extends ModelColumn<K>>(column: C): Promise<Factories[K]["model"][C][]>;
  value<C extends ModelColumn<K>>(column: C): Promise<Factories[K]["model"][C] | null>;
  count(): Promise<number>;
  exists(): Promise<boolean>;
  update(values: FactoryAttributes<K>): Promise<number>;
  delete(): Promise<number>;
}
export type BatchFactory = {
  [K in FactoryName]: FactoryOptions<K> & {
    model: K;
    attrs?: FactoryAttributes<K>;
    count?: number;
    refs?: { [Relation in keyof Factories[K]["relations"]]?: number };
  };
}[FactoryName];
export type BatchResults<Items extends readonly BatchFactory[]> = {
  -readonly [I in keyof Items]: Items[I] extends infer Item
    ? Item extends { model: infer K extends FactoryName }
      ? Item extends { count: number } ? FactoryModel<K>[]
        : "count" extends keyof Item ? FactoryModel<K> | FactoryModel<K>[] : FactoryModel<K>
      : never
    : never;
};
export type BatchChecks<Items extends readonly BatchFactory[]> = {
  [I in keyof Items]: Items[I] extends { model: infer K extends FactoryName; attrs: infer A }
    ? { attrs: A & Record<Exclude<keyof A, keyof FactoryAttributes<K>>, never> }
    : unknown;
};

export type OperationName = keyof Operations & string;
export type OperationInput<K extends OperationName> = Operations[K]["input"];
export type OperationOutput<K extends OperationName> = Operations[K]["output"];
export type ArtisanName = keyof Artisan & string;
export type ArtisanInput<K extends ArtisanName> = Artisan[K];
export type ScheduleName = Schema<"schedules", string>;
type FaultPath = Schema<"faultPaths", string>;
export type FaultTarget = FaultPath | { url: FaultPath; method: string };
export type FaultArguments<T extends RouteTarget, Response> = [T] extends [FaultTarget] ? [response: Response] : [response: never];
export type InputArguments<T> = {} extends T ? [input?: T] : [input: T];
