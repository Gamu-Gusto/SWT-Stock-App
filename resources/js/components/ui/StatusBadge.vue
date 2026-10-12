<script setup lang="ts">
import { computed } from 'vue';
import { CircleCheck, TriangleAlert, CircleX, Clock, Circle } from 'lucide-vue-next';
import { label } from '@/utils/format';

const props = defineProps<{ status: string; text?: string }>();

const tone = computed(() => {
    const s = props.status;
    if (['in_stock', 'completed', 'posted', 'approved', 'available', 'paid', 'active', 'counted', 'converted', 'closed', 'restocked'].includes(s)) return 'ok';
    if (['low_stock', 'pending', 'pending_inspection', 'draft', 'partial', 'recount_requested', 'submitted', 'under_review', 'in_progress', 'partially_returned', 'reserved', 'open'].includes(s)) return 'warn';
    if (['out_of_stock', 'voided', 'rejected', 'cancelled', 'missing', 'written_off', 'unpaid', 'damaged', 'expired', 'reversed'].includes(s)) return 'bad';
    return 'neutral';
});

const classes = {
    ok: 'bg-ok-soft text-ok',
    warn: 'bg-warn-soft text-warn',
    bad: 'bg-bad-soft text-bad',
    neutral: 'bg-line-soft text-muted',
};
const icons = { ok: CircleCheck, warn: Clock, bad: CircleX, neutral: Circle };
const icon = computed(() => (props.status === 'low_stock' ? TriangleAlert : icons[tone.value]));
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[12.5px] font-semibold whitespace-nowrap" :class="classes[tone]">
        <component :is="icon" class="size-3.5" aria-hidden="true" />
        {{ text ?? label(status) }}
    </span>
</template>
