<script setup lang="ts">
import type { ManagedEvent } from "~/utils/management";
useSeoMeta({ title: "Create event — LeggTix", robots: "noindex, nofollow" });
const { identity } = useManagement();
async function saved(event: ManagedEvent) { await navigateTo(`/manage/events/${event.id}`); }
</script>
<template>
  <main id="main" tabindex="-1" class="container account-page management-page">
    <NuxtLink to="/manage/events" class="text-link">← Manage events</NuxtLink>
    <h1 tabindex="-1" class="mt-6">Make something happen.</h1>
    <ManagementAccess>
      <section class="account-card mt-8"><h2>Create an event</h2><ManagedEventForm :key="identity" class="mt-6" @saved="saved" /></section>
    </ManagementAccess>
  </main>
</template>
