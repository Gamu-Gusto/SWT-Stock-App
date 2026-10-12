<script setup lang="ts">
import { CircleCheck, CircleAlert, Info, X } from 'lucide-vue-next';
import { useUi } from '@/stores/ui';

const ui = useUi();
const icons = { success: CircleCheck, error: CircleAlert, info: Info };
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 bottom-20 z-50 flex flex-col items-center gap-2 px-4 lg:bottom-6 lg:items-end lg:pr-6" aria-live="polite">
        <div
            v-for="t in ui.toasts"
            :key="t.id"
            role="status"
            class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-[10px] px-4 py-3 text-sm font-medium shadow-lg"
            :class="t.kind === 'error' ? 'bg-bad text-white' : t.kind === 'info' ? 'bg-asphalt text-white' : 'bg-ok text-white'"
        >
            <component :is="icons[t.kind]" class="mt-0.5 size-4 shrink-0" />
            <span class="flex-1">{{ t.message }}</span>
            <button type="button" class="-m-1 rounded p-1 hover:bg-white/15" aria-label="Dismiss" @click="ui.dismiss(t.id)"><X class="size-4" /></button>
        </div>
    </div>
</template>
