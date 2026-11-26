export default defineNuxtConfig({
  devtools: { enabled: false },
  modules: ["@sti3bas/rehearsal/nuxt"],
  runtimeConfig: { public: { laravelOrigin: "http://127.0.0.1:8100" } },
});
