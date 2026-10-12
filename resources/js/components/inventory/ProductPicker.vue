<script setup lang="ts">
import { ref, watch } from 'vue';
import { Search, LoaderCircle, Plus } from 'lucide-vue-next';
import { get } from '@/api/http';
import type { Product, TyreUnit } from '@/types';
import { formatMoney } from '@/utils/format';
import SizeChip from '@/components/ui/SizeChip.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';

/**
 * Search-as-you-type tyre picker. For used tyres the employee chooses a specific tyre unit.
 * Emits `pick` with the product (and unit for used tyres).
 */
const props = withDefaults(defineProps<{ placeholder?: string; inStockOnly?: boolean; allowUnits?: boolean; autofocus?: boolean }>(), {
    placeholder: 'Search size, brand or SKU – e.g. 205/55R16',
    inStockOnly: false,
    allowUnits: true,
});
const emit = defineEmits<{ pick: [product: Product, unit?: TyreUnit] }>();

const q = ref('');
const results = ref<Product[]>([]);
const loading = ref(false);
const open = ref(false);
const expanded = ref<number | null>(null);
const units = ref<TyreUnit[]>([]);
let timer: ReturnType<typeof setTimeout> | undefined;
let req = 0;

watch(q, (value) => {
    clearTimeout(timer);
    if (value.trim().length < 2) {
        results.value = [];
        return;
    }
    timer = setTimeout(async () => {
        const id = ++req;
        loading.value = true;
        try {
            const res = await get<{ data: Product[] }>('/products', { q: value, per_page: 12, in_stock: props.inStockOnly ? 1 : undefined });
            if (id === req) {
                results.value = res.data;
                open.value = true;
            }
        } finally {
            if (id === req) loading.value = false;
        }
    }, 250);
});

async function choose(p: Product) {
    if (p.tracking === 'unit' && props.allowUnits) {
        if (expanded.value === p.id) {
            expanded.value = null;
            return;
        }
        expanded.value = p.id;
        units.value = (await get<{ data: TyreUnit[] }>(`/products/${p.id}/units`, { status: 'available' })).data;
        return;
    }
    emit('pick', p);
    reset();
}

function chooseUnit(p: Product, u: TyreUnit) {
    emit('pick', p, u);
    reset();
}

function reset() {
    q.value = '';
    results.value = [];
    open.value = false;
    expanded.value = null;
}
</script>

<template>
    <div class="relative">
        <label class="sr-only" for="product-picker">Find tyre</label>
        <div class="relative">
            <Search class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-faint" />
            <input id="product-picker" v-model="q" type="search" class="field !pl-10" :placeholder="placeholder" autocomplete="off" :autofocus="autofocus" @focus="open = results.length > 0" />
            <LoaderCircle v-if="loading" class="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-faint" />
        </div>
        <div v-if="open && q.length >= 2" class="absolute inset-x-0 top-full z-30 mt-1 max-h-[60vh] overflow-y-auto rounded-[10px] border border-line bg-panel shadow-xl">
            <p v-if="!loading && results.length === 0" class="px-4 py-3 text-sm text-muted">No tyres match “{{ q }}”.</p>
            <div v-for="p in results" :key="p.id" class="border-b border-line-soft last:border-0">
                <button type="button" class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-surface" @click="choose(p)">
                    <SizeChip :size="p.spec?.size" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold">{{ p.brand?.name }} {{ p.model }}</span>
                        <span class="block text-[12.5px] text-muted">
                            {{ p.condition === 'used' ? 'Used' : 'New' }} · {{ p.sku }}
                        </span>
                    </span>
                    <span class="text-right">
                        <span class="block font-display text-lg leading-none font-semibold tabular-nums">{{ p.qty_available }}</span>
                        <span class="text-[11.5px] text-muted">available</span>
                    </span>
                    <span class="hidden w-24 text-right text-sm font-semibold sm:block">{{ p.tracking === 'unit' && p.unit_price_range ? `from ${formatMoney(p.unit_price_range[0])}` : formatMoney(p.effective_price) }}</span>
                </button>
                <div v-if="expanded === p.id" class="bg-surface px-3 py-2">
                    <p v-if="units.length === 0" class="py-2 text-sm text-muted">No inspected used tyres available for this size.</p>
                    <button
                        v-for="u in units"
                        :key="u.id"
                        type="button"
                        class="mb-1 flex w-full items-center gap-3 rounded-[6px] border border-line bg-white px-3 py-2 text-left text-sm hover:border-asphalt"
                        @click="chooseUnit(p, u)"
                    >
                        <Plus class="size-4 text-muted" />
                        <span class="font-semibold">{{ u.unit_code }}</span>
                        <span class="text-muted">{{ u.tread_depth_mm ? `${u.tread_depth_mm} mm` : 'tread n/a' }} · grade {{ u.grade ?? '–' }} · {{ u.location?.name }}</span>
                        <StatusBadge class="ml-auto" :status="u.status" />
                        <span class="font-semibold">{{ formatMoney(u.selling_price) }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
