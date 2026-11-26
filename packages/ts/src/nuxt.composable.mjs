import { useNuxtApp, useState } from "#imports";
import { typesenseInstantSearch } from "./typesense.js";

/** @returns {import("ofetch").$Fetch} */
export function useRehearsalFetch() {
  return useNuxtApp().$rehearsalFetch ?? globalThis.$fetch;
}

export function useTypesenseInstantSearch() {
  return typesenseInstantSearch(useState("rehearsal:typesense").value);
}
