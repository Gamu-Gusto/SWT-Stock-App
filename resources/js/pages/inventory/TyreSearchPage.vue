<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Search, SlidersHorizontal } from 'lucide-vue-next';
import { useList } from '@/composables/useList';
import { useLookups } from '@/stores/lookups';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import ProductResults from '@/components/inventory/ProductResults.vue';
import type { Product } from '@/types';

const route = useRoute();
const router = useRouter();
const lookups = useLookups();
const input = ref<HTMLInputElement | null>(null);
const showFilters = ref(false);

const list = useList<Product>('/products', {
    q: String(route.query.q ?? ''),
    condition: '',
    brand_id: '',
    in_stock: '',
    location_id: '',
    price_min: '',
    price_max: '',
    sort: 'relevance',
    per_page: 25,
}, { debounceKeys: ['q', 'price_min', 'price_max'], immediate: !!route.query.q });

const searched = computed(() => !!list.filters.q || !!list.filters.brand_id || !!list.filters.condition || !!list.filters.location_id);
const interpreted = computed(() => list.extra.value?.meta?.interpreted);
const understood = computed(() => {
    const i = interpreted.value;
    if (!i) return '';
    const parts: string[] = [];
    if (i.width || i.profile || i.rim) parts.push(`size ${i.width ?? '…'}/${i.profile ?? '…'} R${i.rim ?? '…'}`);
    if (i.condition) parts.push(i.condition);
    if (i.terms?.length) parts.push(`“${i.terms.join(' ')}”`);
    return parts.join(', ');
});

watch(() => list.filters.q, (q) => router.replace({ query: q ? { q: String(q) } : {} }));
onMounted(() => input.value?.focus());
</script>

<template>
    <div>
        <section class="mb-4">
            <h1 class="mb-3 text-[28px] font-bold sm:text-[32px]">Tyre search</h1>
            <form role="search" @submit.prevent="list.load(1)">
                <label for="size-search" class="sr-only">Tyre size, brand, model, SKU or tyre code</label>
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-4 size-6 -translate-y-1/2 text-faint" />
                    <input
                        id="size-search"
                        ref="input"
                        v-model="list.filters.q"
                        type="search"
                        class="field !min-h-16 !pl-13 !font-display !text-[26px] !font-semibold !tracking-wide"
                        placeholder="205/55 R16"
                        autocomplete="off"
                        enterkeyhint="search"
                    />
                </div>
            </form>
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-muted">
                    <template v-if="understood">Searching for {{ understood }}.</template>
                    <template v-else>Size, brand, model, SKU or used-tyre code. Add “new” or “used” to narrow it down.</template>
                </p>
                <button type="button" class="btn btn-ghost btn-sm" :aria-expanded="showFilters" @click="showFilters = !showFilters"><SlidersHorizontal class="size-4" /> Filters</button>
            </div>
            <div v-if="showFilters" class="mt-3 grid grid-cols-2 gap-3 rounded-[10px] border border-line bg-panel p-4 sm:grid-cols-3 lg:grid-cols-6">
                <div><label class="field-label" for="f-cond">Condition</label><select id="f-cond" v-model="list.filters.condition" class="field"><option value="">New and used</option><option value="new">New</option><option value="used">Used</option></select></div>
                <div><label class="field-label" for="f-brand">Brand</label><select id="f-brand" v-model="list.filters.brand_id" class="field"><option value="">All brands</option><option v-for="b in lookups.brands" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
                <div><label class="field-label" for="f-loc">Location</label><select id="f-loc" v-model="list.filters.location_id" class="field"><option value="">Any location</option><option v-for="l in lookups.activeLocations" :key="l.id" :value="l.id">{{ l.name }}</option></select></div>
                <div><label class="field-label" for="f-min">Price from</label><input id="f-min" v-model="list.filters.price_min" type="number" min="0" class="field" inputmode="decimal" /></div>
                <div><label class="field-label" for="f-max">Price to</label><input id="f-max" v-model="list.filters.price_max" type="number" min="0" class="field" inputmode="decimal" /></div>
                <div><label class="field-label" for="f-stock">Availability</label><select id="f-stock" v-model="list.filters.in_stock" class="field"><option value="">Show all</option><option value="1">Available only</option></select></div>
            </div>
        </section>

        <section class="panel overflow-hidden">
            <div v-if="!searched" class="px-6 py-14 text-center">
                <p class="font-display text-xl font-semibold">Enter the size from the customer’s tyre</p>
                <p class="mt-1 text-sm text-muted">Results show every brand, new and used, with what is physically here, what is reserved and what can be sold now.</p>
            </div>
            <AsyncState v-else :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" empty-title="No tyres match" :empty-text="`Nothing in the system matches “${list.filters.q}”. Check the size or try only the rim, e.g. R16.`" @retry="list.load()">
                <ProductResults :items="list.items.value" show-actions />
                <PaginationBar :page="list.page.value" :last-page="list.lastPage.value" :total="list.total.value" @change="list.load" />
            </AsyncState>
        </section>
        <p class="mt-3 text-xs text-muted">Quantities come straight from the server each time you search. Always confirm availability here before promising stock.</p>
    </div>
</template>
