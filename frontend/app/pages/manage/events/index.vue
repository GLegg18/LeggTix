<script setup lang="ts">
import { api, ApiError } from "~/utils/api";
import { eventSchedule, type CursorPage, type ManagedEvent } from "~/utils/management";
useSeoMeta({ title: "Manage events — LeggTix", robots: "noindex, nofollow" });
const { user, allowed, identity, restore } = useManagement();
const page = ref<CursorPage<ManagedEvent> | null>(null);
const pending = ref(false);
const { message, errors, clear, fail } = useFormErrors();
let revision = 0;
async function load(cursor?: string | null) {
  const request = ++revision;
  clear();
  pending.value = true;
  try {
    const result = await api<CursorPage<ManagedEvent>>(`manage/events${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ""}`);
    if (request === revision) page.value = result;
  } catch (error) {
    if (request !== revision) return;
    if (error instanceof ApiError && error.status === 401) await restore();
    else await fail(error);
  } finally { if (request === revision) pending.value = false; }
}
watch(identity, () => { ++revision; page.value = null; pending.value = false; clear(); if (allowed.value) void load(); }, { immediate: true });
onBeforeUnmount(() => { ++revision; });
</script>
<template>
  <main id="main" tabindex="-1" class="container account-page management-page">
    <p class="eyebrow brand-pink">BEHIND THE GOOD TIMES</p>
    <h1 tabindex="-1" class="mt-4">{{ user?.role === 'admin' ? 'Manage events.' : 'My events.' }}</h1>
    <ManagementAccess>
      <div class="management-toolbar mt-6">
        <p class="text-muted">{{ user?.role === 'admin' ? 'All events, with the same inventory protections.' : 'Create, publish and look after your events.' }}</p>
        <NuxtLink to="/manage/events/new" class="button button-primary">Create event <span aria-hidden="true">↗</span></NuxtLink>
      </div>
      <FormErrors :message="message" :errors="errors" />
      <p v-if="pending" class="mt-8 text-muted" role="status">Loading events…</p>
      <button v-if="message" class="button button-outline mt-5" :disabled="pending" @click="load()">Try again</button>
      <div v-if="page && !pending" class="management-list mt-8">
        <p v-if="!page.data.length" class="account-card text-muted">No events here yet. Create your first draft when you’re ready.</p>
        <article v-for="event in page.data" :key="event.id" class="account-card management-event">
          <div>
            <span class="management-status">{{ event.status }}</span>
            <h2 class="mt-3"><NuxtLink :to="`/manage/events/${event.id}`" class="text-link">{{ event.name }}</NuxtLink></h2>
            <p class="text-muted mt-3">{{ eventSchedule(event) }}</p>
            <p class="text-muted mt-2">{{ event.venue }} · {{ event.confirmed_count }} / {{ event.capacity }} confirmed</p>
          </div>
          <NuxtLink :to="`/manage/events/${event.id}`" class="button button-outline">Manage <span aria-hidden="true">↗</span></NuxtLink>
        </article>
        <nav class="management-toolbar" aria-label="Event pages">
          <button class="button button-outline" :disabled="!page.meta.prev_cursor" @click="load(page.meta.prev_cursor)">Previous events</button>
          <button class="button button-outline" :disabled="!page.meta.next_cursor" @click="load(page.meta.next_cursor)">Next events</button>
        </nav>
      </div>
    </ManagementAccess>
  </main>
</template>
