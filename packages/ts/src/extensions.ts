import type { Laravel } from "./client.js";

declare const operationShape: unique symbol;

export interface Operation<Input extends object, Output> {
  readonly name: string;
  readonly [operationShape]?: (input: Input) => Output;
}

export type OperationDefinitions = Record<string, { readonly name: string }>;

export type BoundOperations<T extends OperationDefinitions> = {
  [K in keyof T]: T[K] extends Operation<infer Input, infer Output> ? (input: Input) => Promise<Output> : never;
};

export function defineOperation<Input extends object, Output = unknown>(name: string): Operation<Input, Output> {
  return { name };
}

export function bindOperations<T extends OperationDefinitions>(operations: T, laravel: Laravel): BoundOperations<T> {
  return Object.fromEntries(Object.entries(operations).map(([key, { name }]) =>
    [key, (input: Record<string, unknown>) => laravel.operation(name, input)],
  )) as BoundOperations<T>;
}
