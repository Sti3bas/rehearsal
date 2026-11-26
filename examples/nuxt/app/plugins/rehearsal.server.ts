import { rehearsalSsrFetch } from "@sti3bas/rehearsal/ssr";

export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig();
  return {
    provide: {
      rehearsalFetch: rehearsalSsrFetch({
        enabled: config.rehearsalEnabled === true || String(config.rehearsalEnabled) === "true",
        laravelOrigin: config.laravelOrigin,
        requestHeaders: Object.fromEntries(
          Object.entries(useRequestHeaders(["cookie", "x-rehearsal-attempt"]))
            .filter((header): header is [string, string] => typeof header[1] === "string"),
        ),
      }),
    },
  };
});
