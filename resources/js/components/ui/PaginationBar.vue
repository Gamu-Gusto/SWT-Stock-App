<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps<{ page: number; lastPage: number; total?: number }>();
const emit = defineEmits<{ change: [page: number] }>();
const go = (p: number) => p >= 1 && p <= props.lastPage && emit('change', p);
</script>

<template>
    <div v-if="lastPage > 1 || total" class="flex items-center justify-between gap-3 border-t border-line-soft px-4 py-3 text-sm text-muted">
        <span>{{ total !== undefined ? `${total} result${total === 1 ? '' : 's'}` : '' }}</span>
        <div v-if="lastPage > 1" class="flex items-center gap-2">
            <button type="button" class="btn btn-secondary btn-sm" :disabled="page <= 1" aria-label="Previous page" @click="go(page - 1)"><ChevronLeft class="size-4" /></button>
            <span class="tabular-nums">Page {{ page }} of {{ lastPage }}</span>
            <button type="button" class="btn btn-secondary btn-sm" :disabled="page >= lastPage" aria-label="Next page" @click="go(page + 1)"><ChevronRight class="size-4" /></button>
        </div>
    </div>
</template>
