<script setup lang="ts">
import { LoaderCircle, CircleAlert, RotateCw } from 'lucide-vue-next';

/** Wraps content with consistent loading and error states. */
defineProps<{ loading: boolean; error?: string | null; empty?: boolean; emptyTitle?: string; emptyText?: string }>();
defineEmits<{ retry: [] }>();
</script>

<template>
    <div v-if="loading" class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-muted" role="status">
        <LoaderCircle class="size-5 animate-spin" /> Loading…
    </div>
    <div v-else-if="error" class="flex flex-col items-center gap-3 px-6 py-10 text-center" role="alert">
        <CircleAlert class="size-8 text-bad" />
        <p class="max-w-md text-sm font-medium text-ink">{{ error }}</p>
        <button type="button" class="btn btn-secondary btn-sm" @click="$emit('retry')"><RotateCw class="size-4" /> Try again</button>
    </div>
    <div v-else-if="empty" class="px-6 py-12 text-center">
        <p class="font-display text-lg font-semibold">{{ emptyTitle ?? 'Nothing here yet' }}</p>
        <p v-if="emptyText" class="mx-auto mt-1 max-w-sm text-sm text-muted">{{ emptyText }}</p>
        <div v-if="$slots.empty" class="mt-4 flex justify-center gap-2"><slot name="empty" /></div>
    </div>
    <slot v-else />
</template>
