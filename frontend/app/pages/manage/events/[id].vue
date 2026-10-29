<script setup lang="ts">
import { api, ApiError } from "~/utils/api";
import { eventSchedule, type ManagedEvent } from "~/utils/management";
useSeoMeta({ title: "Manage event — LeggTix", robots: "noindex, nofollow" });
const route = useRoute();
const { identity, allowed, restore } = useManagement();
const event = ref<ManagedEvent | null>(null);
const pending = ref(false);
const formPending = ref(false);
const confirmCancel = ref(false);
const notice = ref("");
const version = ref(0);
const { message, errors, clear, fail } = useFormErrors();
let revision = 0;
const terminal = computed(() => event.value && ["cancelled", "completed"].includes(event.value.status));
const canComplete = computed(() => event.value?.status === "published" && new Date(event.value.starts_at).getTime() <= Date.now());
async function load() {
  const request = ++revision;
  pending.value = true; clear();
  try {
    const result = await api<{ data: ManagedEvent }>(`manage/events/${route.params.id}`);
    if (request === revision) { event.value = result.data; version.value++; }
  } catch (error) {
    if (request !== revision) return;
    if (error instanceof ApiError && error.status === 401) await restore();
    else await fail(error);
  } finally { if (request === revision) pending.value = false; }
}
function saved(value: ManagedEvent) { event.value = value; notice.value = "Changes saved."; version.value++; }
async function transition(status: "published" | "completed" | "cancelled") {
  if (!event.value || pending.value || formPending.value) return;
  const request = ++revision;
  pending.value = true; clear(); notice.value = "";
  try {
    const result = await api<{ data: ManagedEvent }>(`manage/events/${event.value.id}`, status === "cancelled" ? { method: "DELETE" } : { method: "PATCH", body: { status } });
    if (request !== revision) return;
    saved(result.data); confirmCancel.value = false;
    notice.value = status === "cancelled" ? "Event cancelled. Active reservations and waiters are closed." : status === "completed" ? "Event completed. Confirmed reservations remain as attendance history." : "Event published.";
  } catch (error) {
    if (request !== revision) return;
    if (error instanceof ApiError && error.status === 401) await restore();
    else await fail(error);
  } finally { if (request === revision) pending.value = false; }
}
watch(() => `${identity.value}:${route.params.id}`, () => {
  ++revision; event.value = null; clear(); notice.value = ""; confirmCancel.value = false; pending.value = false;
  if (allowed.value) void load();
}, { immediate: true });
onBeforeUnmount(() => { ++revision; });
</script>
<template>
  <main id="main" tabindex="-1" class="container account-page management-page">
    <NuxtLink to="/manage/events" class="text-link">← Manage events</NuxtLink>
    <h1 tabindex="-1" class="mt-6">{{ event?.name || 'Your event.' }}</h1>
    <ManagementAccess>
      <FormErrors :message="message" :errors="errors" />
      <p v-if="notice" class="notice mt-6" role="status">{{ notice }}</p>
      <p v-if="pending" class="mt-6 text-muted" role="status">Updating event…</p>
      <button v-if="message" class="button button-outline mt-5" :disabled="pending" @click="load">Refresh event</button>
      <template v-if="event">
        <section class="account-card mt-8">
          <div class="management-toolbar"><span class="management-status">{{ event.status }}</span><span class="text-muted">{{ event.confirmed_count }} / {{ event.capacity }} confirmed</span></div>
          <p class="text-muted mt-4">{{ eventSchedule(event) }}</p>
          <p v-if="terminal" class="notice mt-6">This event is closed. You can correct its name, description and venue; its lifecycle and inventory stay fixed.</p>
          <div v-else class="management-actions mt-6">
            <button v-if="event.status === 'draft'" class="button button-primary" :disabled="pending || formPending" @click="transition('published')">Publish event</button>
            <button v-if="canComplete" class="button button-outline" :disabled="pending || formPending" @click="transition('completed')">Mark completed</button>
            <button class="button button-outline" :disabled="pending || formPending" @click="confirmCancel = !confirmCancel">Cancel event</button>
          </div>
          <div v-if="confirmCancel" class="management-confirm mt-6" role="group" aria-labelledby="cancel-heading">
            <h2 id="cancel-heading">Cancel {{ event.name }}?</h2>
            <p class="mt-3">This closes all active reservations and waiting entries. The event cannot be reopened.</p>
            <div class="management-actions mt-5"><button class="button button-primary" :disabled="pending || formPending" @click="transition('cancelled')">Confirm cancellation</button><button class="button button-outline" :disabled="pending || formPending" @click="confirmCancel = false">Keep event</button></div>
          </div>
        </section>
        <section class="account-card mt-6">
          <h2>Event details</h2>
          <ManagedEventForm :key="`${identity}:${version}`" :event="event" :disabled="pending" class="mt-6" @saved="saved" @busy="formPending = $event" />
        </section>
        <ManagedAttemptList :key="`reservations:${version}`" :event-id="event.id" kind="reservations" />
        <ManagedAttemptList :key="`waitlist:${version}`" :event-id="event.id" kind="waitlist" />
      </template>
    </ManagementAccess>
  </main>
</template>
