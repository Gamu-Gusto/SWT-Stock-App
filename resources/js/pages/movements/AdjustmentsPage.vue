<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { Check, X } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import TabLinks from '@/components/ui/TabLinks.vue';
import ProductPicker from '@/components/inventory/ProductPicker.vue';
import FormField from '@/components/ui/FormField.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import SizeChip from '@/components/ui/SizeChip.vue';
import { get, post } from '@/api/http';
import { useList } from '@/composables/useList';
import { useSubmit } from '@/composables/useForm';
import { useAuth } from '@/stores/auth';
import { useLookups } from '@/stores/lookups';
import { useUi } from '@/stores/ui';
import { formatDateTime, formatMoney } from '@/utils/format';
import type { Product, TyreUnit } from '@/types';

const route = useRoute();
const auth = useAuth();
const lookups = useLookups();
const ui = useUi();
const submit = useSubmit();
const list = useList<any>('/adjustments', { status: 'pending' });
const product = ref<Product | null>(null);
const unit = ref<TyreUnit | null>(null);
const form = reactive({ type: 'manual_adjustment', location_id: '' as number | '', quantity_change: '' as number | '', supplier_id: '' as number | '', reason: '' });

const types = [
    { v: 'manual_adjustment', l: 'Correct the quantity', d: 'Add (+) or remove (−) tyres that the system has wrong.' },
    { v: 'damaged', l: 'Damaged stock', d: 'Move tyres out of sellable stock into the damaged area.' },
    { v: 'write_off', l: 'Write off', d: 'Remove tyres that are scrapped or lost.' },
    { v: 'supplier_return', l: 'Return to supplier', d: 'Send tyres back to the supplier.' },
];

function pick(p: Product, u?: TyreUnit) {
    product.value = p;
    unit.value = u ?? null;
    form.location_id = u?.location_id ?? p.locations?.[0]?.location_id ?? p.default_location_id ?? '';
}
onMounted(async () => {
    if (route.query.product_id) {
        const p = (await get<{ data: Product }>(`/products/${route.query.product_id}`)).data;
        if (p.tracking === 'quantity') pick(p);
    }
});
const held = computed(() => product.value?.locations?.find((l) => l.location_id === form.location_id)?.qty ?? 0);

async function request() {
    const body = {
        type: form.type,
        product_id: unit.value ? null : product.value?.id,
        tyre_unit_id: unit.value?.id ?? null,
        location_id: unit.value ? null : form.location_id,
        quantity_change: unit.value ? null : form.quantity_change,
        supplier_id: form.supplier_id || null,
        reason: form.reason,
    };
    const res = await submit.run((key) => post('/adjustments', { ...body, idempotency_key: key }));
    if (res) {
        ui.toast(res.message);
        product.value = null;
        unit.value = null;
        Object.assign(form, { quantity_change: '', reason: '' });
        list.load(1);
    }
}

const decide = useSubmit();
async function decideAdj(a: any, approve: boolean) {
    const notes = approve ? prompt('Approval note (optional)') ?? '' : prompt('Why is it rejected?');
    if (!approve && !notes) return;
    if (await decide.run(() => post(`/adjustments/${a.id}/${approve ? 'approve' : 'reject'}`, { notes }), approve ? 'Adjustment approved and posted.' : 'Adjustment rejected.')) list.load();
}
</script>

<template>
    <div>
        <PageHeader title="Stock movements" subtitle="Stock corrections need a reason and a second person’s approval before stock changes." />
        <TabLinks :tabs="[{ label: 'Movement history', to: '/movements', can: ['movements.view'] }, { label: 'Transfers', to: '/movements/transfers', can: ['transfers.create', 'movements.view'] }, { label: 'Adjustments', to: '/movements/adjustments' }]" />

        <section v-if="auth.can('adjustments.request')" class="panel mb-5 p-5">
            <h2 class="panel-title mb-3">Request an adjustment</h2>
            <div class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <label v-for="t in types" :key="t.v" class="flex cursor-pointer gap-2 rounded-[8px] border p-3" :class="form.type === t.v ? 'border-asphalt ring-2 ring-asphalt/15' : 'border-line'">
                    <input v-model="form.type" type="radio" :value="t.v" class="mt-1 size-4" />
                    <span><span class="block font-semibold">{{ t.l }}</span><span class="text-[13px] text-muted">{{ t.d }}</span></span>
                </label>
            </div>
            <ProductPicker v-if="!product" placeholder="Which tyre? Search size, brand or tyre code" @pick="pick" />
            <div v-else class="grid gap-4 sm:grid-cols-2">
                <div class="flex items-center gap-2 sm:col-span-2"><SizeChip :size="product.spec?.size" /><span class="font-semibold">{{ product.brand?.name }} {{ product.model }}</span><span v-if="unit" class="text-muted">{{ unit.unit_code }}</span><button type="button" class="link ml-2 text-sm" @click="product = null">change</button></div>
                <FormField v-if="!unit" v-slot="{ id }" label="Location" required :error="submit.fieldError('location_id')" :hint="form.location_id ? `${held} at this location` : undefined">
                    <select :id="id" v-model="form.location_id" class="field"><option v-for="l in lookups.activeLocations" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                </FormField>
                <FormField v-if="!unit" v-slot="{ id }" :label="form.type === 'manual_adjustment' ? 'Change (+ or −)' : 'Number of tyres'" required :error="submit.fieldError('quantity_change')">
                    <input :id="id" v-model.number="form.quantity_change" type="number" class="field" :placeholder="form.type === 'manual_adjustment' ? '-1' : '1'" />
                </FormField>
                <FormField v-if="form.type === 'supplier_return'" v-slot="{ id }" label="Supplier" required :error="submit.fieldError('supplier_id')">
                    <select :id="id" v-model="form.supplier_id" class="field"><option value="" disabled>Choose</option><option v-for="s in lookups.suppliers" :key="s.id" :value="s.id">{{ s.company_name }}</option></select>
                </FormField>
                <div class="sm:col-span-2"><FormField v-slot="{ id }" label="Reason" required :error="submit.fieldError('reason')"><textarea :id="id" v-model="form.reason" rows="2" class="field" placeholder="What happened? This is kept permanently with the movement." /></FormField></div>
                <div><button type="button" class="btn btn-primary" :disabled="submit.busy.value || form.reason.length < 5" @click="request">Submit for approval</button></div>
            </div>
        </section>

        <div class="mb-3 flex items-center gap-2">
            <label class="field-label !mb-0" for="adj-s">Show</label>
            <select id="adj-s" v-model="list.filters.status" class="field !w-auto"><option value="pending">Waiting for approval</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="all">All</option></select>
        </div>
        <section class="panel overflow-hidden">
            <AsyncState :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" empty-title="Nothing here" @retry="list.load()">
                <ul class="divide-y divide-line-soft">
                    <li v-for="a in list.items.value" :key="a.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1 text-sm">
                            <p class="font-semibold">{{ a.adjustment_no }} · {{ types.find((t) => t.v === a.type)?.l }} · <span :class="a.quantity_change < 0 || a.type !== 'manual_adjustment' ? 'text-bad' : 'text-ok'">{{ a.type === 'manual_adjustment' && a.quantity_change > 0 ? '+' : a.type !== 'manual_adjustment' ? '−' : '' }}{{ Math.abs(a.quantity_change) }}</span></p>
                            <p>{{ a.product }}<template v-if="a.unit_code"> ({{ a.unit_code }})</template> at {{ a.location }}<template v-if="a.supplier"> → {{ a.supplier }}</template></p>
                            <p class="text-muted">“{{ a.reason }}” – {{ a.requested_by }}, {{ formatDateTime(a.created_at) }}<template v-if="a.estimated_cost"> · approx. {{ formatMoney(a.estimated_cost) }} at cost</template></p>
                            <p v-if="a.decided_by" class="text-muted">{{ a.status === 'approved' ? 'Approved' : 'Rejected' }} by {{ a.decided_by }}{{ a.decision_notes ? `: ${a.decision_notes}` : '' }}</p>
                        </div>
                        <StatusBadge :status="a.status" />
                        <div v-if="a.status === 'pending' && auth.can('adjustments.approve') && (a.requested_by_id !== auth.user?.id || auth.user?.role.name === 'owner')" class="flex gap-2">
                            <button type="button" class="btn btn-dark btn-sm" :disabled="decide.busy.value" @click="decideAdj(a, true)"><Check class="size-4" /> Approve</button>
                            <button type="button" class="btn btn-secondary btn-sm" :disabled="decide.busy.value" @click="decideAdj(a, false)"><X class="size-4" /> Reject</button>
                        </div>
                    </li>
                </ul>
                <PaginationBar :page="list.page.value" :last-page="list.lastPage.value" :total="list.total.value" @change="list.load" />
            </AsyncState>
        </section>
    </div>
</template>
