<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { X } from 'lucide-vue-next';

const props = defineProps<{ open: boolean; title: string; wide?: boolean }>();
const emit = defineEmits<{ close: [] }>();
const panel = ref<HTMLElement | null>(null);
let previous: Element | null = null;

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('close');
}

watch(
    () => props.open,
    async (open) => {
        if (open) {
            previous = document.activeElement;
            document.addEventListener('keydown', onKey);
            await nextTick();
            (panel.value?.querySelector('input, select, textarea, button:not([data-close])') as HTMLElement | null)?.focus();
        } else {
            document.removeEventListener('keydown', onKey);
            (previous as HTMLElement | null)?.focus?.();
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-40 flex items-end justify-center bg-asphalt/50 sm:items-center sm:p-4" @mousedown.self="emit('close')">
            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                class="max-h-[92vh] w-full overflow-y-auto rounded-t-[14px] bg-panel sm:rounded-[12px]"
                :class="wide ? 'sm:max-w-3xl' : 'sm:max-w-lg'"
            >
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-line-soft bg-panel px-5 py-4">
                    <h2 class="text-xl font-semibold">{{ title }}</h2>
                    <button type="button" data-close class="btn btn-ghost btn-sm -mr-2" aria-label="Close" @click="emit('close')"><X class="size-5" /></button>
                </div>
                <div class="px-5 py-4"><slot /></div>
                <div v-if="$slots.footer" class="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-line-soft bg-panel px-5 py-3"><slot name="footer" /></div>
            </div>
        </div>
    </Teleport>
</template>
