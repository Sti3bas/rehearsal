import { addImports, addPlugin, createResolver, defineNuxtModule } from "@nuxt/kit";

interface ModuleOptions {
  laravelOrigin: string;
  forwardCookies: string[];
}

export default defineNuxtModule<ModuleOptions>({
  meta: { name: "rehearsal", configKey: "rehearsal", compatibility: { nuxt: "^4.0.0" } },
  defaults: { laravelOrigin: "http://127.0.0.1:8100", forwardCookies: [] },
  setup(options, nuxt) {
    const { resolve } = createResolver(import.meta.url);
    nuxt.options.runtimeConfig.rehearsal = {
      enabled: false, laravelOrigin: options.laravelOrigin, forwardCookies: options.forwardCookies,
      ...nuxt.options.runtimeConfig.rehearsal as Partial<ModuleOptions>,
    };
    addPlugin({ src: resolve("./nuxt.plugin.mjs"), mode: "server" });
    addImports({ name: "useRehearsalFetch", from: resolve("./nuxt.composable.mjs") });
  },
});
