import type { RehearsalSchema } from "./index.js";
import type { RouteTarget } from "./routes.js";

type Schema<K, Fallback> = K extends keyof RehearsalSchema ? RehearsalSchema[K] : Fallback;
type Row = Record<string, unknown>;
type Factories = Schema<"factories", Record<string, { attributes: Row; model: Row; states: string; relations: Record<string, string> }>>;
type Operations = Schema<"operations", Record<string, { input: Row; output: unknown }>>;
type Artisan = Schema<"artisan", Record<string, Row>>;

export type FactoryName = keyof Factories & string;
export type FactoryAttributes<K extends FactoryName> = Factories[K]["attributes"];
export type FactoryModel<K extends FactoryName> = Factories[K]["model"];
export type FactoryState<K extends FactoryName> = Factories[K]["states"];
export type FactoryOptions<K extends FactoryName> = { states?: readonly FactoryState<K>[] };
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
