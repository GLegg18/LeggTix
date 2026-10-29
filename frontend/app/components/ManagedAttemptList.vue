<script setup lang="ts">
import { api, ApiError } from "~/utils/api";
import type { CursorPage, ManagedAttempt } from "~/utils/management";
const props = defineProps<{ eventId: number; kind: "reservations" | "waitlist" }>();
const { identity, allowed, restore } = useManagement();
const page = ref<CursorPage<ManagedAttempt> | null>(null);
const pending = ref(false);
const { message, errors, clear, fail } = useFormErrors();
let revision = 0;
async function load(cursor?: string | null) {
  const request = ++revision;
  pending.value = true; clear();
  try {
    const result = await api<CursorPage<ManagedAttempt>>(`manage/events/${props.eventId}/${props.kind}${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ""}`);
    if (request === revision) page.value = result;
  } catch (error) {
    if (request !== revision) return;
    if (error instanceof ApiError && error.status === 401) await restore();
    else await fail(error);
  } finally { if (request === revision) pending.value = false; }
}
watch(() => `${identity.value}:${props.eventId}:${props.kind}`, () => {
  ++revision; page.value = null; clear(); pending.value = false;
  if (allowed.value) void load();
}, { immediate: true });
onBeforeUnmount(() => { ++revision; });
</script>
<template>
  <section class="account-card mt-6" :aria-label="kind === 'reservations' ? 'Reservations' : 'Waitlist'">
    <h2>{{ kind === 'reservations' ? 'Reservations' : 'Waitlist history' }}</h2>
    <p class="text-muted mt-3">{{ kind === 'reservations' ? 'Confirmed and cancelled attempts, ordered by reference.' : 'All attempts, ordered by join time then reference. Active waiters are promoted in that order.' }}</p>
    <FormErrors :message="message" :errors="errors" />
    <p v-if="pending" class="mt-6 text-muted" role="status">Loading records…</p>
    <button v-if="message" class="button button-outline mt-4" :disabled="pending" @click="load()">Retry records</button>
    <template v-if="page && !pending">
      <p v-if="!page.data.length" class="mt-6 text-muted">No {{ kind === 'reservations' ? 'reservations' : 'waitlist entries' }} yet.</p>
      <ul v-else class="management-records mt-6">
        <li v-for="attempt in page.data" :key="attempt.id">
          <div><strong>{{ attempt.user.name }}</strong><p class="text-muted text-sm mt-1">Account #{{ attempt.user_id }} · Attempt #{{ attempt.id }}</p><p class="text-muted text-sm mt-1">{{ attempt.joined_at || attempt.created_at }}</p></div>
          <span class="management-status">{{ attempt.status }}</span>
        </li>
      </ul>
      <nav class="management-toolbar mt-6" :aria-label="`${kind} pages`">
        <button class="button button-outline" :disabled="!page.meta.prev_cursor" @click="load(page.meta.prev_cursor)">Previous records</button>
        <button class="button button-outline" :disabled="!page.meta.next_cursor" @click="load(page.meta.next_cursor)">Next records</button>
      </nav>
    </template>
  </section>
</template>
