<script setup lang="ts">
import { inspectLocalDateTime, type TimeOccurrence } from "~/utils/eventDateTime";

const props = defineProps<{
  id: string;
  label: string;
  modelValue: string;
  occurrence: TimeOccurrence | "";
  timezone: string;
  optional?: boolean;
  disabled?: boolean;
  error?: string;
}>();
const emit = defineEmits<{
  "update:modelValue": [value: string];
  "update:occurrence": [value: TimeOccurrence | ""];
}>();
const inspection = computed(() => inspectLocalDateTime(props.modelValue, props.timezone));
const problem = computed(() => props.error || (inspection.value.kind === "gap"
  ? "This time does not exist because the clocks move forward. Choose a time before or after the clock change."
  : ""));
function updateValue(event: Event) {
  emit("update:modelValue", (event.target as HTMLInputElement).value);
  emit("update:occurrence", "");
}
function openPicker(event: MouseEvent) {
  const input = event.currentTarget as HTMLInputElement;
  // Pointer activation opens the picker; tabbing/focus and keyboard editing stay native.
  if (event.detail === 0 || input.matches(":disabled") || input.readOnly || typeof input.showPicker !== "function") return;
  try {
    input.showPicker();
    // Suppress the indicator's default activation after opening the same native picker.
    event.preventDefault();
  } catch (error) {
    // Unsupported or disallowed native pickers must leave normal input editing usable.
    if (!(error instanceof DOMException)) throw error;
  }
}
</script>

<template>
  <div class="field event-date-time">
    <label :for="id">{{ label }}</label>
    <input :id="id" :value="modelValue" type="datetime-local" step="60"
      :required="!optional" :disabled="disabled" :aria-invalid="!!problem" :aria-describedby="`${id}-hint`" @input="updateValue" @click="openPicker" />
    <p :id="`${id}-hint`" :class="problem ? 'field-error' : 'field-hint'">
      {{ problem || (optional ? 'Choose a date and time, or leave empty for no end time.' : 'Choose a date and time, or type them using your keyboard.') }}
    </p>
    <div v-if="inspection.kind === 'ambiguous'" class="event-time-occurrence">
      <label :for="`${id}-occurrence`">{{ label.replace(' (optional)', '') }} occurrence</label>
      <p :id="`${id}-occurrence-hint`" class="field-hint">The clocks move back, so this time occurs twice. Choose which one you mean.</p>
      <select :id="`${id}-occurrence`" :value="occurrence" :disabled="disabled" :aria-invalid="!occurrence && !!error"
        :aria-describedby="`${id}-occurrence-hint`"
        @change="emit('update:occurrence', ($event.target as HTMLSelectElement).value as TimeOccurrence | '')">
        <option value="">Choose an occurrence</option>
        <option v-for="candidate in inspection.candidates" :key="candidate.occurrence" :value="candidate.occurrence">{{ candidate.label }}</option>
      </select>
    </div>
  </div>
</template>
