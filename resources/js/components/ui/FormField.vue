<script setup lang="ts">
import { useId } from 'vue';

/** Accessible label + control + hint/error wrapper. The control receives `id` via the default slot. */
defineProps<{ label: string; error?: string; hint?: string; required?: boolean }>();
const id = useId();
</script>

<template>
    <div>
        <label :for="id" class="field-label">{{ label }}<span v-if="required" class="text-bad" aria-hidden="true"> *</span></label>
        <slot :id="id" :invalid="!!error" />
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <p v-else-if="hint" class="field-hint">{{ hint }}</p>
    </div>
</template>
