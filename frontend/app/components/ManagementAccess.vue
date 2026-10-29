<script setup lang="ts">
const { user, status, restore } = useAuth();
const allowed = computed(() => user.value && ["organiser", "admin"].includes(user.value.role));
</script>
<template>
  <p v-if="status === 'loading'" class="mt-8 text-muted" role="status">Checking your account…</p>
  <section v-else-if="status === 'unavailable'" class="account-card mt-8">
    <h2>We couldn’t reach your account.</h2>
    <button class="button button-primary mt-6" @click="restore">Try again</button>
  </section>
  <section v-else-if="!user" class="account-card mt-8">
    <h2>Sign in to manage events.</h2>
    <NuxtLink to="/login" class="button button-primary mt-6">Sign in</NuxtLink>
  </section>
  <section v-else-if="!allowed" class="account-card mt-8">
    <h2>Organiser access required.</h2>
    <p class="text-muted mt-3">Event management is available to organisers and administrators.</p>
    <NuxtLink to="/account" class="text-link mt-6 inline-block">Your account</NuxtLink>
  </section>
  <slot v-else />
</template>
