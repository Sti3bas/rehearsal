import { addImports, addPlugin, createResolver, defineNuxtModule } from "@nuxt/kit";

interface ModuleOptions {
  laravelOrigin: string;
}

export default defineNuxtModule<ModuleOptions>({
  meta: { name: "rehearsal", configKey: "rehearsal", compatibility: { nuxt: "^4.0.0" } },
  defaults: { laravelOrigin: "http://127.0.0.1:8100" },
  setup(options, nuxt) {
    const { resolve } = createResolver(import.meta.url);
    nuxt.options.runtimeConfig.rehearsal ??= { enabled: false, laravelOrigin: options.laravelOrigin };
    addPlugin({ src: resolve("./nuxt.plugin.mjs"), mode: "server" });
    addImports({ name: "useRehearsalFetch", from: resolve("./nuxt.composable.mjs") });
  },
});
