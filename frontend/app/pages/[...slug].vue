<script setup lang="ts">
useSeoMeta({ title: "Page not found — LeggTix", robots: "noindex, nofollow" });
</script>
<template>
  <main id="main" tabindex="-1" class="container not-found">
    <p class="eyebrow brand-pink">404 / A LITTLE OFF THE BEATEN TRACK</p>
    <h1 tabindex="-1">
      This isn’t<br />the place<span class="brand-pink">.</span>
    </h1>
    <p class="mt-6 text-muted max-w-md leading-relaxed">
      We couldn’t find that page. It may have moved, or the link might have a
      typo. Let’s get you back somewhere familiar.
    </p>
    <NuxtLink to="/" class="button button-primary mt-8"
      >Back to home <span aria-hidden="true">↗</span></NuxtLink
    >
  </main>
</template>
