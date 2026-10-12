<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Check, Search, Send, ScanLine, Plus, Minus } from 'lucide-vue-next';
import AsyncState from '@/components/ui/AsyncState.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import SizeChip from '@/components/ui/SizeChip.vue';
import { get, post, type ApiError } from '@/api/http';
import { useLookups } from '@/stores/lookups';
import { useUi } from '@/stores/ui';
import { useSubmit } from '@/composables/useForm';

const route = useRoute();
const router = useRouter();
const lookups = useLookups();
const ui = useUi();
const id = route.params.id;
const st = ref<any>(null);
const items = ref<any[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const filter = ref<'uncounted' | 'counted' | ''>('uncounted');
const locationId = ref<number | ''>('');
const q = ref('');
const submit = useSubmit();

async function load() {
    try {
        st.value ??= (await get(`/stocktakes/${id}`)).data;
        items.value = (await get(`/stocktakes/${id}/items`, { filter: filter.value, location_id: locationId.value, q: q.value })).data;
        error.value = null;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
let t: ReturnType<typeof setTimeout> | undefined;
watch([filter, locationId], load);
watch(q, () => {
    clearTimeout(t);
    t = setTimeout(load, 300);
});

// Counting a line
const active = ref<any>(null);
const qty = ref<number>(0);
function open(item: any) {
    active.value = item;
    qty.value = item.counted_qty ?? (item.tyre_unit_id ? 1 : 0);
}
async function save(replace = false) {
    const res = await submit.run(() => post(`/stocktakes/${id}/count`, { item_id: active.value.id, counted_qty: qty.value, replace }));
    if (res) {
        ui.toast(`Counted ${qty.value} × ${active.value.product}`);
        active.value = null;
        load();
    } else if (submit.error.value?.code === 'duplicate_count' && confirm(`${submit.error.value.message}`)) {
        save(true);
    }
}

// Found something not on the list (tyre code or product)
const addOpen = ref(false);
const unitCode = ref('');
const searchQ = ref('');
const found = ref<any[]>([]);
const addLocation = ref<number | ''>('');
const addQty = ref(1);
watch(searchQ, async (v) => {
    found.value = v.length >= 2 ? (await get(`/stocktakes/${id}/products`, { q: v })).data : [];
});
async function addUnexpected(product?: any) {
    const body = product ? { product_id: product.id, location_id: addLocation.value, counted_qty: addQty.value } : { unit_code: unitCode.value, location_id: addLocation.value, counted_qty: 1 };
    const res = await submit.run(() => post(`/stocktakes/${id}/count`, body), 'Count saved.');
    if (res) {
        addOpen.value = false;
        unitCode.value = '';
        searchQ.value = '';
        load();
    }
}

const allCounted = computed(() => st.value && items.value.length === 0 && filter.value === 'uncounted');
async function submitCount(asZero = false) {
    const res = await submit.run(() => post(`/stocktakes/${id}/submit`, { uncounted_as_zero: asZero }), 'Count submitted for review.');
    if (res) router.replace('/stocktakes');
    else if (submit.error.value?.code === 'uncounted_lines' && confirm(`${submit.error.value.message}\n\nSubmit and record the uncounted lines as NOT FOUND (zero)?`)) submitCount(true);
}
</script>

<template>
    <AsyncState :loading="loading" :error="error" @retry="load">
        <div v-if="st" class="mx-auto max-w-3xl">
            <header class="mb-4">
                <RouterLink :to="`/stocktakes/${id}`" class="text-sm font-medium text-muted hover:text-ink">‹ {{ st.stocktake_no }}</RouterLink>
                <h1 class="text-[28px] font-bold">Physical count</h1>
                <p class="text-muted">{{ st.title }} · {{ st.scope_label }}</p>
                <p v-if="st.status !== 'in_progress'" class="mt-2 rounded-[8px] bg-warn-soft px-3 py-2 text-sm text-warn">This stocktake is {{ st.status.replace('_', ' ') }} – counting is closed.</p>
                <p v-else-if="st.is_blind && !st.can_view_expected" class="mt-2 text-sm text-muted">Count what is physically there. The system quantity is hidden on purpose.</p>
            </header>

            <div class="sticky top-16 z-10 -mx-4 mb-3 space-y-2 bg-surface px-4 py-2">
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-faint" />
                    <input v-model="q" type="search" class="field !pl-10" placeholder="Find size, brand or tyre code" aria-label="Find line" />
                </div>
                <div class="flex gap-2">
                    <select v-model="filter" class="field" aria-label="Show"><option value="uncounted">Still to count</option><option value="counted">Counted</option><option value="">All lines</option></select>
                    <select v-model="locationId" class="field" aria-label="Location"><option value="">All locations</option><option v-for="l in lookups.activeLocations" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                </div>
            </div>

            <ul class="space-y-2">
                <li v-for="i in items" :key="i.id">
                    <button type="button" class="flex w-full items-center gap-3 rounded-[10px] border bg-panel p-3 text-left" :class="i.status === 'recount_requested' ? 'border-warn' : 'border-line'" :disabled="st.status !== 'in_progress'" @click="open(i)">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2"><SizeChip :size="i.size" /><span class="text-sm font-semibold" :class="i.condition === 'used' ? 'text-warn' : 'text-muted'">{{ i.condition === 'used' ? 'Used' : 'New' }}</span></div>
                            <p class="mt-1 font-semibold">{{ i.product }}</p>
                            <p class="text-[13px] text-muted">{{ i.location }}<template v-if="i.unit_code"> · tyre {{ i.unit_code }}</template><template v-if="i.snapshot_qty !== undefined"> · expected {{ i.system_qty_at_count ?? i.snapshot_qty }}</template></p>
                            <p v-if="i.status === 'recount_requested'" class="text-[13px] font-semibold text-warn">Recount: {{ i.recount_reason }}</p>
                        </div>
                        <div class="text-right">
                            <p v-if="i.counted_qty !== null && i.status !== 'recount_requested'" class="flex items-center gap-1 font-display text-3xl font-bold tabular-nums"><Check class="size-5 text-ok" />{{ i.counted_qty }}</p>
                            <span v-else class="btn btn-dark btn-sm">Count</span>
                        </div>
                    </button>
                </li>
            </ul>
            <p v-if="!items.length" class="rounded-[10px] border border-dashed border-line px-4 py-8 text-center text-muted">{{ allCounted ? 'Every line is counted. Submit the count when you are done.' : 'No lines match.' }}</p>

            <div v-if="st.status === 'in_progress'" class="mt-5 grid gap-2 sm:grid-cols-2">
                <button type="button" class="btn btn-secondary" @click="addOpen = true"><ScanLine class="size-4" /> Found something not on the list</button>
                <button type="button" class="btn btn-primary" :disabled="submit.busy.value" @click="submitCount()"><Send class="size-4" /> Submit count</button>
            </div>

            <ModalDialog :open="!!active" :title="active ? `Count ${active.product}` : ''" @close="active = null">
                <template v-if="active">
                    <p class="mb-4 text-sm text-muted">{{ active.location }}<template v-if="active.unit_code"> · tyre {{ active.unit_code }}</template></p>
                    <div v-if="active.tyre_unit_id" class="grid grid-cols-2 gap-2">
                        <button type="button" class="btn !min-h-16 text-lg" :class="qty === 1 ? 'btn-dark' : 'btn-secondary'" @click="qty = 1">Found</button>
                        <button type="button" class="btn !min-h-16 text-lg" :class="qty === 0 ? 'btn-danger' : 'btn-secondary'" @click="qty = 0">Not found</button>
                    </div>
                    <div v-else class="flex items-center justify-center gap-3">
                        <button type="button" class="btn btn-secondary !size-16 !p-0" aria-label="One less" :disabled="qty <= 0" @click="qty--"><Minus class="size-6" /></button>
                        <input v-model.number="qty" type="number" min="0" inputmode="numeric" class="field !h-20 !w-32 text-center font-display !text-5xl font-bold" aria-label="Counted quantity" />
                        <button type="button" class="btn btn-secondary !size-16 !p-0" aria-label="One more" @click="qty++"><Plus class="size-6" /></button>
                    </div>
                    <p v-if="active.counted_qty !== null && active.status !== 'recount_requested'" class="mt-3 text-center text-sm text-warn">Already counted as {{ active.counted_qty }}. Saving replaces that count (the earlier count is kept in the history).</p>
                </template>
                <template #footer>
                    <button type="button" class="btn btn-secondary" @click="active = null">Cancel</button>
                    <button type="button" class="btn btn-primary" :disabled="submit.busy.value || qty < 0" @click="save(active?.counted_qty !== null && active?.status !== 'recount_requested')">Save count</button>
                </template>
            </ModalDialog>

            <ModalDialog :open="addOpen" title="Found something not on the list" @close="addOpen = false">
                <label class="field-label" for="add-loc">Where is it?</label>
                <select id="add-loc" v-model="addLocation" class="field mb-4"><option value="" disabled>Choose location</option><option v-for="l in lookups.activeLocations" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                <label class="field-label" for="add-code">Used tyre code</label>
                <div class="mb-4 flex gap-2"><input id="add-code" v-model="unitCode" class="field" placeholder="UT-2026-000123" /><button type="button" class="btn btn-dark" :disabled="!unitCode || !addLocation" @click="addUnexpected()">Add</button></div>
                <label class="field-label" for="add-search">Or a new tyre (search)</label>
                <input id="add-search" v-model="searchQ" class="field" placeholder="205/55R16 Falken" />
                <div class="mt-2 flex items-center gap-2"><label for="add-qty" class="text-sm">Quantity found</label><input id="add-qty" v-model.number="addQty" type="number" min="0" class="field !w-24" /></div>
                <ul class="mt-2 divide-y divide-line-soft">
                    <li v-for="p in found.filter((f: any) => f.tracking === 'quantity')" :key="p.id" class="flex items-center justify-between py-2 text-sm">
                        <span><SizeChip :size="p.size" /> {{ p.display_name }}</span>
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="!addLocation" @click="addUnexpected(p)">Add</button>
                    </li>
                </ul>
            </ModalDialog>
        </div>
    </AsyncState>
</template>
