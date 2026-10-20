<script setup lang="ts">
defineProps<{ eyebrow: string; title: string; description: string }>();
</script>
<template>
  <main id="main" tabindex="-1" class="container auth-layout">
    <aside class="auth-story" aria-label="Welcome to LeggTix">
      <p class="eyebrow">A little less planning</p>
      <p class="auth-story-title">A lot more<br /><span>being there.</span></p>
      <div class="auth-story-art" aria-hidden="true">
        <div class="orbit orbit-one" />
        <div class="orbit orbit-two" />
        <img src="/brand/leggtix-ticket.svg" width="108" height="108" alt="" />
      </div>
      <p class="text-base leading-relaxed">
        The best moments happen when you show up.<br class="hidden xl:block" />
        Let’s get you ready for the next one.
      </p>
      <span class="eyebrow auth-story-foot"
        >Your next good time starts here ↗</span
      >
    </aside>
    <section class="auth-panel">
      <p class="eyebrow brand-pink">{{ eyebrow }}</p>
      <h1 tabindex="-1" class="mt-4">{{ title }}</h1>
      <p class="mt-4 text-muted leading-relaxed">{{ description }}</p>
      <div class="mt-8"><slot /></div>
    </section>
  </main>
</template>
