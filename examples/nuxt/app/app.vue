<script setup lang="ts">
const { data } = await useAsyncData("state", async () => {
  const response = await useNuxtApp().$rehearsalFetch("/state");
  if (!response.ok) throw createError({ statusCode: response.status });
  return await response.json() as { users: string[] };
});
</script>

<template>
  <main>
    <ul>
      <li v-for="name in data?.users" :key="name" v-text="name" />
    </ul>
  </main>
</template>
