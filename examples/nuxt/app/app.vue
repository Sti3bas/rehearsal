<script setup lang="ts">
const { laravelOrigin } = useRuntimeConfig().public;
const { data } = await useFetch<{ users: string[] }>("/state", {
  baseURL: laravelOrigin,
  $fetch: useRehearsalFetch(),
});
</script>

<template>
  <main>
    <ul>
      <li v-for="name in data?.users" :key="name" v-text="name" />
    </ul>
  </main>
</template>
