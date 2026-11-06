<script setup lang="ts">
import { api, ApiError } from "~/utils/api";
import type { CursorPage, EventTypeChoice, ManagedEvent } from "~/utils/management";
import { resolveLocalDateTime, scheduleTimeZones, scheduleValue, type ScheduleValue, type TimeOccurrence } from "~/utils/eventDateTime";
const props = defineProps<{ event?: ManagedEvent; disabled?: boolean }>();
const emit = defineEmits<{ saved: [event: ManagedEvent]; busy: [value: boolean] }>();
const { identity, restore } = useManagement();
const types = ref<EventTypeChoice[]>([]);
const pending = ref(false);
const loadingTypes = ref(true);
const saved = ref(false);
const { message, errors, clear, fail } = useFormErrors();
const form = reactive({ name: props.event?.name ?? "", description: props.event?.description ?? "", venue: props.event?.venue ?? "", starts_at: props.event?.starts_at ?? "", ends_at: props.event?.ends_at ?? "", timezone: props.event?.timezone ?? "Europe/London", capacity: props.event?.capacity ?? 1, event_type_id: props.event?.event_type.id ?? 0 });
const startSchedule = reactive(scheduleValue(form.starts_at, form.timezone));
const endSchedule = reactive(scheduleValue(form.ends_at, form.timezone));
const timeZones = computed(() => scheduleTimeZones(form.timezone));
const terminal = computed(() => !!props.event && ["cancelled", "completed"].includes(props.event.status));
const frozen = computed(() => !!props.event?.has_history || terminal.value);
const original = { ...form };
const currentTypeMissing = computed(() => !!props.event && !types.value.some(type => type.id === props.event?.event_type.id));
const initialIdentity = identity.value;
let active = true;
onBeforeUnmount(() => { active = false; });
async function loadTypes() {
  loadingTypes.value = true;
  clear();
  try {
    const choices: EventTypeChoice[] = [];
    let cursor: string | null = null;
    do {
      const page: CursorPage<EventTypeChoice> = await api(`manage/event-types?per_page=100${cursor ? `&cursor=${encodeURIComponent(cursor)}` : ""}`);
      choices.push(...page.data);
      cursor = page.meta.next_cursor;
    } while (cursor && active && initialIdentity === identity.value);
    if (!active || initialIdentity !== identity.value) return;
    types.value = choices;
    if (!form.event_type_id && choices[0]) form.event_type_id = choices[0].id;
  } catch (error) {
    if (!active || initialIdentity !== identity.value) return;
    if (error instanceof ApiError && error.status === 401) await restore();
    else await fail(error);
  } finally { if (active) loadingTypes.value = false; }
}
onMounted(loadTypes);
function updateSchedule(schedule: ScheduleValue, field: "starts_at" | "ends_at", value: string) {
  schedule.local = value;
  schedule.occurrence = "";
  delete errors.value[field];
}
function updateOccurrence(schedule: ScheduleValue, field: "starts_at" | "ends_at", value: TimeOccurrence | "") {
  schedule.occurrence = value;
  delete errors.value[field];
}
function changeTimeZone(event: Event) {
  const nextZone = (event.target as HTMLSelectElement).value;
  for (const schedule of [startSchedule, endSchedule]) {
    const resolved = resolveLocalDateTime(schedule.local, form.timezone, schedule.occurrence, schedule.preservedInstant);
    if (resolved.instant) Object.assign(schedule, scheduleValue(resolved.instant, nextZone));
    else schedule.occurrence = "";
  }
  form.timezone = nextZone;
  delete errors.value.timezone;
  delete errors.value.starts_at;
  delete errors.value.ends_at;
}
async function submit() {
  if (pending.value || props.disabled) return;
  emit("busy", true);
  pending.value = true; saved.value = false; clear();
  try {
    const start: ReturnType<typeof resolveLocalDateTime> = frozen.value ? { instant: props.event!.starts_at } : resolveLocalDateTime(startSchedule.local, form.timezone, startSchedule.occurrence, startSchedule.preservedInstant);
    const end: ReturnType<typeof resolveLocalDateTime> = frozen.value ? { instant: props.event!.ends_at } : resolveLocalDateTime(endSchedule.local, form.timezone, endSchedule.occurrence, endSchedule.preservedInstant);
    if (start.error || !start.instant) errors.value.starts_at = [start.error || "Choose a start date and time."];
    if (end.error) errors.value.ends_at = [end.error];
    if (Object.keys(errors.value).length) {
      message.value = "Check the event dates and times.";
      await nextTick();
      document.querySelector<HTMLElement>("[data-error-summary]")?.focus();
      return;
    }
    const values: Record<string, unknown> = { ...form, starts_at: start.instant, ends_at: end.instant, capacity: Number(form.capacity), event_type_id: Number(form.event_type_id) };
    if (props.event) {
      for (const key of Object.keys(values)) {
        const before = key === "ends_at" ? original.ends_at || null : original[key as keyof typeof original];
        if (values[key] === before) delete values[key];
      }
      if (!Object.keys(values).length) { saved.value = true; return; }
    }
    const result = await api<{ data: ManagedEvent }>(props.event ? `manage/events/${props.event.id}` : "manage/events", { method: props.event ? "PATCH" : "POST", body: values });
    if (!active || initialIdentity !== identity.value) return;
    saved.value = true;
    emit("saved", result.data);
  } catch (error) {
    if (!active || initialIdentity !== identity.value) return;
    if (error instanceof ApiError && error.status === 401) await restore();
    else await fail(error);
  } finally { emit("busy", false); if (active) pending.value = false; }
}
const fields = [
  { name: "name", label: "Event name", max: 255 },
  { name: "venue", label: "Venue", max: 255 },
] as const;
</script>
<template>
  <form class="form-stack management-form" @submit.prevent="submit">
    <fieldset :disabled="disabled" class="form-stack min-w-0">
    <legend class="sr-only">Event information</legend>
    <FormErrors :message="message" :errors="errors" />
    <p v-if="saved" class="notice" role="status">Changes saved.</p>
    <p v-if="frozen && !terminal" class="notice">This event has reservation or waitlist history. Its schedule and type are fixed; rescheduling needs a separate workflow.</p>
    <div v-for="field in fields" :key="field.name" class="field">
      <label :for="`event-${field.name}`">{{ field.label }}</label>
      <input :id="`event-${field.name}`" v-model="form[field.name]" type="text" :maxlength="field.max" required :disabled="pending" :aria-invalid="!!errors[field.name]" :aria-describedby="errors[field.name] ? `event-${field.name}-hint` : undefined" />
      <p v-if="errors[field.name]" :id="`event-${field.name}-hint`" class="field-error">{{ errors[field.name]?.[0] }}</p>
    </div>
    <fieldset class="event-schedule form-stack" :disabled="pending || frozen" aria-describedby="event-schedule-hint">
      <legend>Event schedule</legend>
      <p id="event-schedule-hint" class="field-hint">Choose dates and times in the event's time zone.</p>
      <div class="management-form-grid">
        <EventDateTimeInput id="event-starts_at" label="Start date and time" :model-value="startSchedule.local" :occurrence="startSchedule.occurrence" :timezone="form.timezone" :error="errors.starts_at?.[0]" :disabled="pending || frozen"
          @update:model-value="updateSchedule(startSchedule, 'starts_at', $event)" @update:occurrence="updateOccurrence(startSchedule, 'starts_at', $event)" />
        <EventDateTimeInput id="event-ends_at" label="End date and time (optional)" :model-value="endSchedule.local" :occurrence="endSchedule.occurrence" :timezone="form.timezone" :error="errors.ends_at?.[0]" :disabled="pending || frozen" optional
          @update:model-value="updateSchedule(endSchedule, 'ends_at', $event)" @update:occurrence="updateOccurrence(endSchedule, 'ends_at', $event)" />
      </div>
      <div class="field">
        <label for="event-timezone">Time zone</label>
        <select id="event-timezone" :value="form.timezone" :disabled="pending || frozen" :aria-invalid="!!errors.timezone" aria-describedby="event-timezone-hint" @change="changeTimeZone">
          <option v-for="zone in timeZones" :key="zone.value" :value="zone.value">{{ zone.label }}</option>
        </select>
        <p id="event-timezone-hint" :class="errors.timezone ? 'field-error' : 'field-hint'">{{ errors.timezone?.[0] || 'Changing the time zone keeps selected times at the same moment and displays them in the new zone. Unfinished entries use the new zone.' }}</p>
      </div>
    </fieldset>
    <div class="field">
      <label for="event-description">Description</label>
      <textarea id="event-description" v-model="form.description" rows="5" required :disabled="pending" :aria-invalid="!!errors.description" aria-describedby="event-description-error" />
      <p v-if="errors.description" id="event-description-error" class="field-error">{{ errors.description[0] }}</p>
    </div>
    <div class="management-form-grid">
      <div class="field">
        <label for="event-capacity">Capacity</label>
        <input id="event-capacity" v-model.number="form.capacity" type="number" min="1" max="4294967295" step="1" required :disabled="pending || terminal" :aria-invalid="!!errors.capacity" aria-describedby="event-capacity-hint" />
        <p id="event-capacity-hint" :class="errors.capacity ? 'field-error' : 'field-hint'">{{ errors.capacity?.[0] || 'Extra places go to existing waiters first.' }}</p>
      </div>
      <div class="field">
        <label for="event-type">Event type</label>
        <select id="event-type" v-model.number="form.event_type_id" required :disabled="pending || loadingTypes || frozen" :aria-invalid="!!errors.event_type_id" aria-describedby="event-type-hint">
          <option v-if="event && currentTypeMissing" :value="event.event_type.id">{{ event.event_type.name }} (current)</option>
          <option v-for="type in types" :key="type.id" :value="type.id">{{ type.name }}</option>
        </select>
        <p id="event-type-hint" :class="errors.event_type_id ? 'field-error' : 'field-hint'">{{ errors.event_type_id?.[0] || (loadingTypes ? 'Loading event types…' : 'Choose an active event type for new events and publication.') }}</p>
        <button v-if="message && !types.length" type="button" class="text-link" @click="loadTypes">Retry event types</button>
      </div>
    </div>
    <p v-if="!event" class="field-hint">Your event starts as a draft. Review it before publishing.</p>
    <button type="submit" class="button button-primary" :disabled="pending || loadingTypes || (!event && !types.length)">{{ pending ? 'Saving…' : event ? 'Save changes' : 'Create draft' }}</button>
    </fieldset>
  </form>
</template>
