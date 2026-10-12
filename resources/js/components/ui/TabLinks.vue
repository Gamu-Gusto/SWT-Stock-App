<script setup lang="ts">
import { computed } from 'vue';
import { useAuth } from '@/stores/auth';

const props = defineProps<{ tabs: { label: string; to: string; can?: string[] }[] }>();
const auth = useAuth();
const visible = computed(() => props.tabs.filter((t) => !t.can || auth.can(...t.can)));
</script>

<template>
    <nav class="no-print -mx-4 mb-5 flex gap-1 overflow-x-auto border-b border-line px-4 sm:mx-0 sm:px-0" aria-label="Section">
        <RouterLink
            v-for="t in visible"
            :key="t.to"
            :to="t.to"
            exact-active-class="!border-brand !text-ink"
            class="-mb-px border-b-2 border-transparent px-3 py-2.5 text-sm font-semibold whitespace-nowrap text-muted hover:text-ink"
        >
            {{ t.label }}
        </RouterLink>
    </nav>
</template>
