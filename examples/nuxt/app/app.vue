<script setup lang="ts">
const laravelOrigin = useState("laravelOrigin", () =>
  import.meta.server ? useRuntimeConfig().rehearsal.laravelOrigin : undefined,
);
const { data } = await useFetch<{ users: string[] }>("/state", {
  baseURL: laravelOrigin,
  $fetch: useRehearsalFetch(),
});
const { data: user } = useRoute().query.authenticated ? await useFetch<{ name: string }>("/api/user", {
  baseURL: laravelOrigin,
  $fetch: useRehearsalFetch(),
  headers: { accept: "application/json" },
}) : { data: null };
</script>

<template>
  <main>
    <p v-if="user" data-testid="authenticated-user">{{ user.name }}</p>
    <ul>
      <li v-for="name in data?.users" :key="name" v-text="name" />
    </ul>
  </main>
</template>
