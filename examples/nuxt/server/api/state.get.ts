import { ofetch } from "ofetch";

export default defineEventHandler(async () => {
  const baseURL = useRuntimeConfig().rehearsal.laravelOrigin;
  const [global, imported, redirect] = await Promise.all([
    $fetch("/state", { baseURL }),
    ofetch("/state", { baseURL }),
    ofetch.raw("/probe/redirect", { baseURL }),
  ]);
  return { global, imported, redirect: redirect.status };
});
