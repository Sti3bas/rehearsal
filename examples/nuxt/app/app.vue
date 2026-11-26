<script setup lang="ts">
const { data } = await useFetch<{ users: string[] }>("/state", {
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
