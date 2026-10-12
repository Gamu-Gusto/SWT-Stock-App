<script setup lang="ts">
import { ChevronLeft } from 'lucide-vue-next';

defineProps<{ title: string; subtitle?: string; back?: string }>();
</script>

<template>
    <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <RouterLink v-if="back" :to="back" class="no-print mb-1 inline-flex items-center gap-1 text-sm font-medium text-muted hover:text-ink">
                <ChevronLeft class="size-4" /> Back
            </RouterLink>
            <h1 class="text-[28px] font-bold text-ink sm:text-[32px]">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 max-w-2xl text-[15px] text-muted">{{ subtitle }}</p>
            <slot name="meta" />
        </div>
        <div v-if="$slots.actions" class="no-print flex flex-wrap gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
