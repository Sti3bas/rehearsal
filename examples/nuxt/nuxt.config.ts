export default defineNuxtConfig({
  app: { rootId: "rehearsal-example" },
  devtools: { enabled: false },
  modules: ["@sti3bas/rehearsal/nuxt"],
  rehearsal: { forwardCookies: ["rehearsal-session", "XSRF-TOKEN"] },
});
