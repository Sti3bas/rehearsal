import { useNuxtApp } from "#imports";

/** @returns {import("ofetch").$Fetch} */
export function useRehearsalFetch() {
  return useNuxtApp().$rehearsalFetch ?? globalThis.$fetch;
}
