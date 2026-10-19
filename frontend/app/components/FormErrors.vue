<script setup lang="ts">
defineProps<{ message: string; errors: Record<string, string[]> }>();
</script>
<template>
  <div
    v-if="message"
    class="error-summary"
    role="alert"
    tabindex="-1"
    data-error-summary
  >
    <p class="font-semibold">{{ message }}</p>
    <ul v-if="Object.keys(errors).length" class="mt-2 list-disc pl-5">
      <li v-for="(messages, field) in errors" :key="field">
        {{ messages[0] }}
      </li>
    </ul>
  </div>
</template>
