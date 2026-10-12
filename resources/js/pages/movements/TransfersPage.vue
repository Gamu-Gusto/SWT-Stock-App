<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowRight, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import TabLinks from '@/components/ui/TabLinks.vue';
import ProductPicker from '@/components/inventory/ProductPicker.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import SizeChip from '@/components/ui/SizeChip.vue';
import { get, post } from '@/api/http';
import { useList } from '@/composables/useList';
import { useSubmit } from '@/composables/useForm';
import { useAuth } from '@/stores/auth';
import { useLookups } from '@/stores/lookups';
import { useUi } from '@/stores/ui';
import { formatDateTime } from '@/utils/format';
import type { Product, TyreUnit } from '@/types';

interface Line { key: string; product_id: number; tyre_unit_id?: number; name: string; size?: string; unit_code?: string; quantity: number; locations: Product['locations'] }

const route = useRoute();
const auth = useAuth();
const lookups = useLookups();
const ui = useUi();
const submit = useSubmit();
const history = auth.can('movements.view') ? useList<any>('/transfers', {}) : null;
const form = reactive({ from_location_id: '' as number | '', to_location_id: '' as number | '', notes: '' });
const lines = ref<Line[]>([]);

async function add(p: Product, u?: TyreUnit) {
    const full = p.locations ? p : (await get<{ data: Product }>(`/products/${p.id}`)).data;
    const key = u ? `u${u.id}` : `p${p.id}`;
    if (!lines.value.some((l) => l.key === key)) {
        lines.value.push({ key, product_id: p.id, tyre_unit_id: u?.id, unit_code: u?.unit_code, name: `${p.brand?.name ?? ''} ${p.model}`, size: p.spec?.size, quantity: 1, locations: full.locations });
    }
}
onMounted(async () => {
    if (route.query.product_id) add((await get<{ data: Product }>(`/products/${route.query.product_id}`)).data);
});
const heldAt = (l: Line) => l.locations?.find((x) => x.location_id === form.from_location_id)?.qty ?? 0;
const invalid = computed(() => !form.from_location_id || !form.to_location_id || form.from_location_id === form.to_location_id || !lines.value.length);

async function save() {
    const res = await submit.run((key) => post('/transfers', {
        ...form,
        idempotency_key: key,
        items: lines.value.map((l) => (l.tyre_unit_id ? { tyre_unit_id: l.tyre_unit_id } : { product_id: l.product_id, quantity: l.quantity })),
    }));
    if (res) {
        ui.toast(res.message);
        lines.value = [];
        history?.load(1);
    }
}
</script>

<template>
    <div>
        <PageHeader title="Stock movements" subtitle="Move tyres between racks and areas. Both sides are updated together in one step." />
        <TabLinks :tabs="[{ label: 'Movement history', to: '/movements', can: ['movements.view'] }, { label: 'Transfers', to: '/movements/transfers' }, { label: 'Adjustments', to: '/movements/adjustments', can: ['adjustments.request', 'adjustments.approve'] }]" />

        <section v-if="auth.can('transfers.create')" class="panel mb-5 p-5">
            <h2 class="panel-title mb-3">New transfer</h2>
            <div class="mb-4 grid items-end gap-3 sm:grid-cols-[1fr_auto_1fr]">
                <div><label class="field-label" for="t-from">From</label><select id="t-from" v-model="form.from_location_id" class="field"><option value="" disabled>Choose</option><option v-for="l in lookups.activeLocations" :key="l.id" :value="l.id">{{ l.name }}</option></select></div>
                <ArrowRight class="mx-auto mb-3 hidden size-5 text-muted sm:block" />
                <div><label class="field-label" for="t-to">To</label><select id="t-to" v-model="form.to_location_id" class="field"><option value="" disabled>Choose</option><option v-for="l in lookups.activeLocations" :key="l.id" :value="l.id" :disabled="l.id === form.from_location_id">{{ l.name }}{{ l.is_sellable ? '' : ' (not sellable)' }}</option></select></div>
            </div>
            <ProductPicker @pick="add" />
            <ul v-if="lines.length" class="mt-3 divide-y divide-line-soft rounded-[10px] border border-line">
                <li v-for="(l, i) in lines" :key="l.key" class="flex flex-wrap items-center gap-3 p-3">
                    <SizeChip :size="l.size" /><span class="flex-1 font-semibold">{{ l.name }} <span v-if="l.unit_code" class="text-muted">{{ l.unit_code }}</span></span>
                    <span v-if="form.from_location_id && !l.tyre_unit_id" class="text-sm text-muted">{{ heldAt(l) }} at source</span>
                    <input v-if="!l.tyre_unit_id" v-model.number="l.quantity" type="number" min="1" class="field !w-24" :aria-label="`Quantity of ${l.name}`" />
                    <button type="button" class="btn btn-ghost btn-sm text-bad" aria-label="Remove" @click="lines.splice(i, 1)"><Trash2 class="size-4" /></button>
                </li>
            </ul>
            <div class="mt-3 flex flex-wrap gap-2">
                <input v-model="form.notes" class="field flex-1" placeholder="Reason / notes (optional)" aria-label="Notes" />
                <button type="button" class="btn btn-primary" :disabled="invalid || submit.busy.value" @click="save">Transfer stock</button>
            </div>
        </section>

        <section v-if="history" class="panel overflow-hidden">
            <div class="panel-head"><h2 class="panel-title">Recent transfers</h2></div>
            <AsyncState :loading="history.loading.value && !history.items.value.length" :error="history.error.value" :empty="!history.items.value.length" empty-title="No transfers yet" @retry="history.load()">
                <ul class="divide-y divide-line-soft text-sm">
                    <li v-for="t in history.items.value" :key="t.id" class="px-5 py-3">
                        <p><span class="font-semibold">{{ t.transfer_no }}</span> · {{ t.from }} → {{ t.to }} · {{ formatDateTime(t.created_at) }} · {{ t.user }}</p>
                        <p class="text-muted">{{ t.items.map((i: any) => `${i.quantity} × ${i.product}${i.unit_code ? ` (${i.unit_code})` : ''}`).join(', ') }}<template v-if="t.notes"> – {{ t.notes }}</template></p>
                    </li>
                </ul>
                <PaginationBar :page="history.page.value" :last-page="history.lastPage.value" @change="history.load" />
            </AsyncState>
        </section>
    </div>
</template>
