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
      { text: "Integrations", link: "/integrations/", activeMatch: "/integrations/" },
      { text: "Frontends", link: "/frontends/", activeMatch: "/frontends/" },
      { text: "Reference", link: "/reference/typescript", activeMatch: "/reference/" },
    ],
    sidebar: [
      {
        text: "Guide",
        items: [
          { text: "Introduction", link: "/guide/getting-started" },
          { text: "Installation", link: "/guide/installation" },
          { text: "Configuration", link: "/guide/configuration" },
          { text: "Extend and override", link: "/guide/extending" },
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
        text: "Integrations",
        items: [
          { text: "Overview", link: "/integrations/" },
          { text: "Database", link: "/integrations/database" },
          { text: "Filesystem", link: "/integrations/filesystem" },
          { text: "Laravel State", link: "/integrations/laravel-state" },
          { text: "Clock", link: "/integrations/clock" },
          { text: "Mailpit", link: "/integrations/mailpit" },
          {
            text: "Laravel Scout",
            link: "/integrations/scout/",
            collapsed: true,
            items: [
              { text: "Database engine", link: "/integrations/scout/database" },
              { text: "Meilisearch", link: "/integrations/scout/meilisearch" },
              { text: "Typesense", link: "/integrations/scout/typesense" },
            ],
          },
          { text: "Reverb", link: "/integrations/reverb" },
          { text: "Writing your own", link: "/integrations/writing-your-own" },
        ],
      },
      {
        text: "Frontends",
        items: [
          { text: "Overview", link: "/frontends/" },
          { text: "Inertia", link: "/frontends/inertia" },
          { text: "Nuxt", link: "/frontends/nuxt" },
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
