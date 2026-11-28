import { defineConfig } from "vitepress";

const repository = "https://github.com/Sti3bas/rehearsal";

export default defineConfig({
  lang: "en",
  title: "Rehearsal",
  description: "Parallel Playwright testing for Laravel",
  base: "/rehearsal/",
  lastUpdated: true,
  head: [["link", { rel: "icon", type: "image/svg+xml", href: "/rehearsal/rehearsal-mark.svg" }]],
  themeConfig: {
    logo: "/rehearsal-mark.svg",
    search: { provider: "local" },
    nav: [
      { text: "Guide", link: "/guide/getting-started", activeMatch: "/guide/" },
      { text: "Concepts", link: "/concepts/isolation", activeMatch: "/concepts/" },
      { text: "Reference", link: "/reference/typescript", activeMatch: "/reference/" },
    ],
    sidebar: [
      {
        text: "Guide",
        items: [
          { text: "Getting started", link: "/guide/getting-started" },
          { text: "Configuration", link: "/guide/configuration" },
          { text: "Extend and override", link: "/guide/extending" },
          { text: "Service integrations", link: "/guide/services" },
          { text: "SSR and Nuxt", link: "/guide/ssr" },
          { text: "CI and performance", link: "/guide/ci" },
        ],
      },
      {
        text: "Concepts",
        items: [
          { text: "Runs, slots, and attempts", link: "/concepts/isolation" },
          { text: "Auto-discovery", link: "/concepts/discovery" },
          { text: "Factories and generated types", link: "/concepts/factories" },
          { text: "Eloquent model queries", link: "/concepts/model-queries" },
          { text: "Named operations", link: "/concepts/operations" },
          { text: "Mocks and callbacks", link: "/concepts/mocking" },
          { text: "Queues and schedules", link: "/concepts/background-work" },
        ],
      },
      {
        text: "Reference",
        items: [
          { text: "TypeScript API", link: "/reference/typescript" },
          { text: "PHP attributes", link: "/reference/attributes" },
          { text: "Artisan commands", link: "/reference/commands" },
        ],
      },
    ],
    socialLinks: [{ icon: "github", link: repository }],
    editLink: {
      pattern: `${repository}/edit/main/docs/:path`,
      text: "Edit this page on GitHub",
    },
    footer: { message: "Released under the MIT License." },
  },
});
