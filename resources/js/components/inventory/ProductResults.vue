<script setup lang="ts">
import { CalendarClock, ShoppingCart, FileText, MapPin } from 'lucide-vue-next';
import type { Product } from '@/types';
import { formatMoney } from '@/utils/format';
import { useAuth } from '@/stores/auth';
import SizeChip from '@/components/ui/SizeChip.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';

defineProps<{ items: Product[]; showActions?: boolean }>();
const auth = useAuth();

function price(p: Product) {
    if (p.tracking === 'unit') {
        if (!p.unit_price_range) return '–';
        return p.unit_price_range[0] === p.unit_price_range[1] ? formatMoney(p.unit_price_range[0]) : `${formatMoney(p.unit_price_range[0])} – ${formatMoney(p.unit_price_range[1])}`;
    }
    return formatMoney(p.effective_price);
}
const locations = (p: Product) => (p.locations ?? []).filter((l) => l.is_sellable).map((l) => `${l.name} (${l.qty})`).join(', ') || '–';
</script>

<template>
    <!-- Desktop table -->
    <div class="hidden overflow-x-auto md:block">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Tyre</th>
                    <th>Size</th>
                    <th>Condition</th>
                    <th class="num">Price each</th>
                    <th class="num">Physical</th>
                    <th class="num">Reserved</th>
                    <th class="num">Available</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th v-if="showActions"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in items" :key="p.id">
                    <td>
                        <RouterLink :to="`/inventory/${p.id}`" class="font-semibold hover:underline">{{ p.brand?.name }} {{ p.model }}</RouterLink>
                        <div class="text-[12.5px] text-muted">{{ p.sku }}<template v-if="p.spec?.load_index"> · {{ p.spec.load_index }}{{ p.spec.speed_rating }}</template><template v-if="p.spec?.run_flat"> · run flat</template></div>
                    </td>
                    <td><SizeChip :size="p.spec?.size" /></td>
                    <td><span class="font-medium" :class="p.condition === 'used' ? 'text-warn' : ''">{{ p.condition === 'used' ? 'Used' : 'New' }}</span></td>
                    <td class="num font-semibold whitespace-nowrap">{{ price(p) }}</td>
                    <td class="num">{{ p.qty_physical }}</td>
                    <td class="num">{{ p.qty_reserved || '–' }}</td>
                    <td class="num"><span class="qty">{{ p.qty_available }}</span></td>
                    <td class="max-w-[180px] text-[13px] text-muted">{{ locations(p) }}</td>
                    <td><StatusBadge :status="p.status === 'archived' ? 'archived' : p.stock_status" /></td>
                    <td v-if="showActions" class="whitespace-nowrap">
                        <div class="flex justify-end gap-1">
                            <RouterLink v-if="auth.can('reservations.manage') && p.qty_available > 0" :to="`/reservations/new?product_id=${p.id}`" class="btn btn-ghost btn-sm" title="Reserve"><CalendarClock class="size-4" /><span class="sr-only">Reserve</span></RouterLink>
                            <RouterLink v-if="auth.can('sales.create') && p.qty_available > 0" :to="`/sales/new?product_id=${p.id}`" class="btn btn-ghost btn-sm" title="Record sale"><ShoppingCart class="size-4" /><span class="sr-only">Record sale</span></RouterLink>
                            <RouterLink v-if="auth.can('quotations.manage')" :to="`/quotations/new?product_id=${p.id}`" class="btn btn-ghost btn-sm" title="Quote"><FileText class="size-4" /><span class="sr-only">Generate quote</span></RouterLink>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Phone cards -->
    <ul class="divide-y divide-line-soft md:hidden">
        <li v-for="p in items" :key="p.id" class="px-4 py-3">
            <RouterLink :to="`/inventory/${p.id}`" class="flex items-start gap-3">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <SizeChip :size="p.spec?.size" />
                        <span class="text-[13px] font-semibold" :class="p.condition === 'used' ? 'text-warn' : 'text-muted'">{{ p.condition === 'used' ? 'Used' : 'New' }}</span>
                    </div>
                    <p class="mt-1 font-semibold">{{ p.brand?.name }} {{ p.model }}</p>
                    <p class="text-sm font-semibold">{{ price(p) }} <span class="font-normal text-muted">each</span></p>
                    <p class="mt-0.5 flex items-center gap-1 text-[12.5px] text-muted"><MapPin class="size-3.5" /> {{ locations(p) }}</p>
                </div>
                <div class="text-right">
                    <p class="font-display text-[30px] leading-none font-bold tabular-nums">{{ p.qty_available }}</p>
                    <p class="text-[12px] text-muted">available</p>
                    <p v-if="p.qty_reserved" class="text-[12px] text-muted">{{ p.qty_reserved }} reserved</p>
                    <StatusBadge class="mt-1" :status="p.stock_status" />
                </div>
            </RouterLink>
            <div v-if="showActions && p.qty_available > 0" class="mt-2 grid grid-cols-3 gap-2">
                <RouterLink v-if="auth.can('reservations.manage')" :to="`/reservations/new?product_id=${p.id}`" class="btn btn-secondary btn-sm">Reserve</RouterLink>
                <RouterLink v-if="auth.can('sales.create')" :to="`/sales/new?product_id=${p.id}`" class="btn btn-dark btn-sm">Sell</RouterLink>
                <RouterLink v-if="auth.can('quotations.manage')" :to="`/quotations/new?product_id=${p.id}`" class="btn btn-secondary btn-sm">Quote</RouterLink>
            </div>
        </li>
    </ul>
</template>
