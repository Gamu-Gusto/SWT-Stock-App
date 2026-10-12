<script setup lang="ts">
import { computed } from 'vue';
import { useLookups } from '@/stores/lookups';
import { estimate } from '@/utils/money';
import { formatMoney } from '@/utils/format';
import type { CartLine, ServiceLine } from '@/types/cart';

const props = defineProps<{ lines: CartLine[]; services: ServiceLine[] }>();
const lookups = useLookups();
const totals = computed(() =>
    estimate(
        [...props.lines.map((l) => ({ unit_price: l.unit_price, quantity: l.quantity, discount: l.discount })), ...props.services.map((s) => ({ unit_price: s.unit_price, quantity: s.quantity, discount: s.discount }))],
        { registered: !!lookups.settings?.vat_registered, rate: lookups.settings?.vat_rate ?? '0', inclusive: lookups.settings?.prices_include_vat ?? true },
    ),
);
defineExpose({ totals });
</script>

<template>
    <dl class="space-y-1.5 text-sm">
        <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd class="tabular-nums">{{ formatMoney(totals.subtotal) }}</dd></div>
        <div v-if="totals.discount !== '0.00'" class="flex justify-between"><dt class="text-muted">Discounts</dt><dd class="tabular-nums">−{{ formatMoney(totals.discount) }}</dd></div>
        <div v-if="lookups.settings?.vat_registered" class="flex justify-between"><dt class="text-muted">VAT {{ lookups.settings.vat_rate }}% {{ lookups.settings.prices_include_vat ? '(included)' : '' }}</dt><dd class="tabular-nums">{{ formatMoney(totals.vat) }}</dd></div>
        <div class="flex items-baseline justify-between border-t border-line pt-2"><dt class="font-semibold">Total</dt><dd class="font-display text-[30px] font-bold tabular-nums">{{ formatMoney(totals.total) }}</dd></div>
    </dl>
</template>
