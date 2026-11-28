<script setup lang="ts">
import { withBase } from "vitepress";

defineProps<{
  items: {
    title: string;
    href: string;
    description: string;
    badge?: string;
  }[];
}>();
</script>

<template>
  <ul class="doc-grid">
    <li v-for="item in items" :key="item.href">
      <a :href="withBase(item.href)">
        <span class="card-heading">
          <strong>{{ item.title }}</strong>
          <span v-if="item.badge" class="card-badge">{{ item.badge }}</span>
        </span>
        <span class="card-description">{{ item.description }}</span>
      </a>
    </li>
  </ul>
</template>

<style scoped>
.doc-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 12px;
  padding: 0;
  margin: 24px 0;
  list-style: none;
}

.doc-grid li {
  display: flex;
  margin: 0;
}

.doc-grid a {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 8px;
  padding: 16px;
  border: 1px solid var(--vp-c-divider);
  border-radius: 12px;
  background: var(--vp-c-bg-soft);
  color: var(--vp-c-text-1);
  text-decoration: none;
}

.doc-grid a:hover,
.doc-grid a:focus-visible {
  border-color: var(--vp-c-brand-1);
}

.card-heading {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 4px 8px;
}

.card-badge {
  padding: 0 8px;
  border-radius: 999px;
  background: var(--vp-c-brand-soft);
  color: var(--vp-c-brand-1);
  font-size: 11px;
  line-height: 20px;
}

.card-description {
  color: var(--vp-c-text-2);
  font-size: 14px;
  line-height: 1.5;
}
</style>
