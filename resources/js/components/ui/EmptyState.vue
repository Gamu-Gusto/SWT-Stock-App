<script setup lang="ts">
import type { Component } from 'vue';
import { Inbox } from 'lucide-vue-next';

defineProps<{ title: string; text?: string; icon?: Component }>();
</script>

<template>
    <div class="flex flex-col items-center px-6 py-12 text-center">
        <component :is="icon ?? Inbox" class="mb-3 size-9 text-faint" aria-hidden="true" />
        <p class="font-display text-lg font-semibold">{{ title }}</p>
        <p v-if="text" class="mt-1 max-w-sm text-sm text-muted">{{ text }}</p>
        <div v-if="$slots.default" class="mt-4 flex flex-wrap justify-center gap-2"><slot /></div>
    </div>
</template>
