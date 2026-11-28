import DefaultTheme from "vitepress/theme";
import DocGrid from "./DocGrid.vue";
import "./style.css";

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    app.component("DocGrid", DocGrid);
  },
} satisfies typeof DefaultTheme;
