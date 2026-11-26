<script setup lang="ts">
import TypesenseInstantsearchAdapter from "typesense-instantsearch-adapter";

const { configure, indexName } = useTypesenseInstantSearch();
const adapter = new TypesenseInstantsearchAdapter(configure({
  server: { apiKey: "example", nodes: [{ host: "localhost", port: 8108, protocol: "http" }], cacheSearchResultsForSeconds: 0 },
  collectionSpecificSearchParameters: { users: { query_by: "name" } },
}));
const search = async () => {
  const { results } = await adapter.searchClient.search([{ indexName: indexName("users"), params: { query: "" } }]);
  return (results[0] as { hits: { name: string }[] }).hits.map(({ name }) => name).sort().join(", ");
};
const { data } = await useAsyncData("typesense-users", search);
const browserResults = ref("");
const hydrated = ref(false);
onMounted(() => { hydrated.value = true; });
async function searchInBrowser() {
  browserResults.value = await search();
}
</script>

<template>
  <p data-testid="typesense-ssr">{{ data }}</p>
  <button data-testid="typesense-search" :disabled="!hydrated" @click="searchInBrowser">Search in browser</button>
  <p data-testid="typesense-browser">{{ browserResults }}</p>
</template>
