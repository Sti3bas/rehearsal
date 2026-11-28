import { addDevServerHandler, addImports, addPlugin, createResolver, defineNuxtModule } from "@nuxt/kit";
import { fakeBridgeHeader, isLoopbackPeer } from "./fake-bridge.js";

interface ModuleOptions {
  laravelOrigin: string;
  forwardCookies: string[];
  typesense: boolean;
}

export default defineNuxtModule<ModuleOptions>({
  meta: { name: "rehearsal", configKey: "rehearsal", compatibility: { nuxt: "^4.0.0" } },
  defaults: { laravelOrigin: "http://127.0.0.1:8100", forwardCookies: [], typesense: false },
  setup(options, nuxt) {
    const { resolve } = createResolver(import.meta.url);
    const runtime = {
      enabled: false, laravelOrigin: options.laravelOrigin, forwardCookies: options.forwardCookies, typesense: options.typesense,
      ...nuxt.options.runtimeConfig.rehearsal as Partial<ModuleOptions & { enabled: boolean }>,
    };
    nuxt.options.runtimeConfig.rehearsal = runtime;
    if (nuxt.options.dev && (process.env.NUXT_REHEARSAL_ENABLED === "true" || runtime.enabled)) {
      addDevServerHandler({ handler: (event) => {
        if (!isLoopbackPeer(event.node.req.socket.remoteAddress)) delete event.node.req.headers[fakeBridgeHeader];
      } });
    }
    addPlugin({ src: resolve("./nuxt.plugin.mjs"), mode: "server" });
    nuxt.hook("nitro:config", (config) => {
      (config.plugins ??= []).push(resolve("./nitro.plugin.mjs"));
    });
    addImports({ name: "useRehearsalFetch", from: resolve("./nuxt.composable.mjs") });
    addImports({ name: "useTypesenseInstantSearch", from: resolve("./nuxt.composable.mjs") });
  },
});
