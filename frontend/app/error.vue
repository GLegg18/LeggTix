<script setup lang="ts">
defineProps<{ error: { statusCode?: number } }>();
</script>
<template>
  <main id="main" tabindex="-1" class="container not-found">
    <p class="eyebrow brand-pink">A SMALL DETOUR</p>
    <h1>Let’s try<br />that again<span class="brand-pink">.</span></h1>
    <p class="mt-6 text-muted">
      {{
        error.statusCode === 404
          ? "We couldn’t find that page."
          : "We hit a snag while loading this page."
      }}
    </p>
    <button
      class="button button-primary mt-8"
      @click="clearError({ redirect: '/' })"
    >
      Back to home ↗
    </button>
  </main>
</template>
