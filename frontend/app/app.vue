<script setup lang="ts">
const route = useRoute();
const { restore } = useAuth();
onMounted(() => restore());
watch(
  () => route.path,
  () => {
    nextTick(() => document.querySelector<HTMLElement>("main h1")?.focus());
  },
);
</script>

<template>
  <a class="skip-link" href="#main">Skip to content</a>
  <div class="site-shell">
    <SiteHeader />
    <NuxtPage />
    <SiteFooter />
  </div>
  <NuxtRouteAnnouncer />
</template>
