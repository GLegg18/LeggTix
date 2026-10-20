<script setup lang="ts">
withDefaults(
  defineProps<{
    id: string;
    label?: string;
    autocomplete: string;
    error?: string;
    hint?: string;
    disabled?: boolean;
  }>(),
  { label: "Password", error: "", hint: "", disabled: false },
);
const model = defineModel<string>({ required: true });
const visible = ref(false);
</script>
<template>
  <div class="field">
    <label :for="id">{{ label }}</label>
    <div class="password-wrap">
      <input
        :id="id"
        v-model="model"
        :type="visible ? 'text' : 'password'"
        :autocomplete="autocomplete"
        required
        :disabled="disabled"
        :aria-invalid="!!error"
        :aria-describedby="
          error ? `${id}-error` : hint ? `${id}-hint` : undefined
        "
      /><button
        type="button"
        class="password-toggle"
        :aria-label="`${visible ? 'Hide' : 'Show'} ${label.toLowerCase()}`"
        :aria-pressed="visible"
        :disabled="disabled"
        @click="visible = !visible"
      >
        {{ visible ? "Hide" : "Show" }}
      </button>
    </div>
    <p v-if="error" :id="`${id}-error`" class="field-error">{{ error }}</p>
    <p v-else-if="hint" :id="`${id}-hint`" class="field-hint">{{ hint }}</p>
  </div>
</template>
