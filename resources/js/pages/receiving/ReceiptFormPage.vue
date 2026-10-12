<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Trash2, Plus, CircleCheck } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import FormField from '@/components/ui/FormField.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import ProductPicker from '@/components/inventory/ProductPicker.vue';
import SizeChip from '@/components/ui/SizeChip.vue';
import { get, post, put } from '@/api/http';
import { useLookups } from '@/stores/lookups';
import { useAuth } from '@/stores/auth';
import { useSubmit } from '@/composables/useForm';
import { formatMoney, todayIso } from '@/utils/format';
import type { Product } from '@/types';

interface UnitDetail { tread_depth_mm: string; grade: string; dot_code: string; defects: string; selling_price: string; cost_price: string; status: string }
interface Line { product_id: number; name: string; size?: string; condition: string; tracking: string; current: number; location_id: number | ''; quantity: number; unit_cost: string; unit_details: UnitDetail[] }

const route = useRoute();
const router = useRouter();
const lookups = useLookups();
const auth = useAuth();
const editing = computed(() => !!route.params.id);
const submit = useSubmit();
const reviewing = ref(false);
const duplicateRef = ref<string | null>(null);

const form = reactive({ supplier_id: '' as number | '', supplier_reference: '', delivery_date: todayIso(), notes: '' });
const lines = ref<Line[]>([]);

onMounted(async () => {
    if (!editing.value) return;
    const r = (await get(`/receipts/${route.params.id}`)).data;
    Object.assign(form, { supplier_id: r.supplier_id, supplier_reference: r.supplier_reference ?? '', delivery_date: r.delivery_date, notes: r.notes ?? '' });
    lines.value = r.items.map((i: any) => ({
        product_id: i.product_id, name: i.product, condition: i.condition, tracking: i.tracking, current: i.current_stock ?? 0,
        location_id: i.location_id, quantity: i.quantity, unit_cost: i.unit_cost ?? '', unit_details: i.unit_details ?? [],
    }));
});

function addProduct(p: Product) {
    if (lines.value.some((l) => l.product_id === p.id)) return;
    lines.value.push({
        product_id: p.id, name: `${p.brand?.name ?? ''} ${p.model}`, size: p.spec?.size, condition: p.condition, tracking: p.tracking,
        current: p.qty_physical, location_id: p.default_location_id ?? '', quantity: p.tracking === 'unit' ? 0 : 1, unit_cost: p.cost_price ?? '',
        unit_details: p.tracking === 'unit' ? [blankUnit(p.selling_price)] : [],
    });
}
function blankUnit(price = ''): UnitDetail {
    return { tread_depth_mm: '', grade: '', dot_code: '', defects: '', selling_price: price, cost_price: '', status: auth.can('used.inspect') ? 'available' : 'pending_inspection' };
}

const qty = (l: Line) => (l.tracking === 'unit' ? l.unit_details.length : Number(l.quantity) || 0);
const totalQty = computed(() => lines.value.reduce((s, l) => s + qty(l), 0));
// Display-only estimate; the server calculates the authoritative total with exact decimals.
const totalCost = computed(() => lines.value.reduce((s, l) => s + (l.tracking === 'unit' ? l.unit_details.reduce((a, d) => a + Number(d.cost_price || l.unit_cost || 0), 0) : qty(l) * Number(l.unit_cost || 0)), 0));
const supplierName = computed(() => lookups.suppliers.find((s) => s.id === form.supplier_id)?.company_name);
const locationName = (id: number | '') => lookups.locations.find((l) => l.id === id)?.name ?? '–';

function payload(post = false, allowDuplicate = false) {
    return {
        ...form,
        post,
        allow_duplicate_reference: allowDuplicate,
        items: lines.value.map((l) => ({
            product_id: l.product_id,
            location_id: l.location_id || null,
            quantity: l.tracking === 'unit' ? null : l.quantity,
            unit_cost: l.unit_cost === '' ? null : l.unit_cost,
            unit_details: l.tracking === 'unit' ? l.unit_details.map((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]))) : null,
        })),
    };
}

async function saveDraft() {
    const res = await submit.run((key) => (editing.value ? put(`/receipts/${route.params.id}`, payload()) : post('/receipts', { ...payload(), idempotency_key: key })), 'Draft saved. Stock has not changed yet.');
    if (res) router.replace(`/receiving/${res.data.id}`);
}

async function confirmPost(allowDuplicate = false) {
    duplicateRef.value = null;
    const res = await submit.run(async (key) => {
        if (editing.value) {
            await put(`/receipts/${route.params.id}`, payload());
            return post(`/receipts/${route.params.id}/post`, { allow_duplicate_reference: allowDuplicate });
        }
        return post('/receipts', { ...payload(true, allowDuplicate), idempotency_key: key });
    }, 'Delivery posted. Stock updated.');
    if (res) router.replace(`/receiving/${res.data.id}`);
    else if (submit.error.value?.code === 'duplicate_reference') duplicateRef.value = submit.error.value.message;
}
</script>

<template>
    <div class="max-w-5xl">
        <PageHeader :title="editing ? 'Edit draft receipt' : 'Receive stock'" back="/receiving" subtitle="Record a supplier delivery. Save as a draft to finish later; stock changes only when you post it." />

        <form class="space-y-5" @submit.prevent="reviewing = true">
            <section class="panel">
                <div class="panel-head"><h2 class="panel-title">Delivery</h2></div>
                <div class="grid gap-4 p-5 sm:grid-cols-3">
                    <FormField v-slot="{ id }" label="Supplier" required :error="submit.fieldError('supplier_id')">
                        <select :id="id" v-model="form.supplier_id" class="field" required><option value="" disabled>Choose supplier</option><option v-for="s in lookups.suppliers" :key="s.id" :value="s.id">{{ s.company_name }}</option></select>
                    </FormField>
                    <FormField v-slot="{ id }" label="Invoice or delivery note no." :error="submit.fieldError('supplier_reference')"><input :id="id" v-model="form.supplier_reference" class="field" /></FormField>
                    <FormField v-slot="{ id }" label="Delivery date" required :error="submit.fieldError('delivery_date')"><input :id="id" v-model="form.delivery_date" type="date" :max="todayIso()" class="field" required /></FormField>
                    <div class="sm:col-span-3"><FormField v-slot="{ id }" label="Notes"><input :id="id" v-model="form.notes" class="field" /></FormField></div>
                </div>
                <p v-if="!lookups.suppliers.length" class="border-t border-line-soft px-5 py-3 text-sm text-warn">No suppliers yet. <RouterLink to="/suppliers" class="link">Add a supplier</RouterLink> first.</p>
            </section>

            <section class="panel">
                <div class="panel-head"><h2 class="panel-title">Tyres received</h2><span class="text-sm text-muted">{{ totalQty }} tyres</span></div>
                <div class="p-5">
                    <ProductPicker placeholder="Find the tyre that arrived – e.g. 205/55R16 Falken" @pick="addProduct" />
                    <p class="mt-2 text-sm text-muted">Tyre not in the system yet? <RouterLink v-if="auth.can('inventory.manage')" to="/inventory/new" target="_blank" class="link">Add the tyre record</RouterLink>, then search for it here.</p>
                </div>
                <div v-for="(l, i) in lines" :key="l.product_id" class="border-t border-line-soft p-5">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <SizeChip :size="l.size" />
                        <span class="font-semibold">{{ l.name }}</span>
                        <span class="text-sm" :class="l.condition === 'used' ? 'text-warn' : 'text-muted'">{{ l.condition === 'used' ? 'Used' : 'New' }}</span>
                        <span class="text-sm text-muted">· currently {{ l.current }} in stock</span>
                        <button type="button" class="btn btn-ghost btn-sm ml-auto text-bad" @click="lines.splice(i, 1)"><Trash2 class="size-4" /> Remove</button>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-4">
                        <FormField v-slot="{ id }" label="Store in" required :error="submit.fieldError(`items.${i}.location_id`)">
                            <select :id="id" v-model="l.location_id" class="field" required><option value="" disabled>Choose</option><option v-for="loc in lookups.activeLocations" :key="loc.id" :value="loc.id">{{ loc.name }}</option></select>
                        </FormField>
                        <FormField v-if="l.tracking !== 'unit'" v-slot="{ id }" label="Quantity received" required :error="submit.fieldError(`items.${i}.quantity`)">
                            <input :id="id" v-model.number="l.quantity" type="number" min="1" inputmode="numeric" class="field font-display !text-xl font-semibold" required />
                        </FormField>
                        <FormField v-slot="{ id }" :label="l.tracking === 'unit' ? 'Default cost per tyre' : 'Cost per tyre'" :error="submit.fieldError(`items.${i}.unit_cost`)">
                            <input :id="id" v-model="l.unit_cost" type="number" step="0.01" min="0" inputmode="decimal" class="field" />
                        </FormField>
                        <div v-if="l.tracking !== 'unit'" class="self-end pb-2 text-sm text-muted">After posting: <strong class="text-ink">{{ l.current + qty(l) }}</strong> in stock</div>
                    </div>
                    <div v-if="l.tracking === 'unit'" class="mt-3 space-y-2">
                        <p class="text-sm font-semibold">Each used tyre</p>
                        <div v-for="(d, j) in l.unit_details" :key="j" class="grid grid-cols-2 gap-2 rounded-[8px] bg-surface p-3 sm:grid-cols-7">
                            <input v-model="d.tread_depth_mm" type="number" step="0.1" min="0" class="field" placeholder="Tread mm" aria-label="Tread depth in mm" />
                            <select v-model="d.grade" class="field" aria-label="Grade" :disabled="!auth.can('used.inspect')"><option value="">Grade</option><option v-for="g in lookups.settings?.used_tyre_grades" :key="g" :value="g">{{ g }}</option></select>
                            <input v-model="d.dot_code" class="field" placeholder="DOT" aria-label="DOT code" />
                            <input v-model="d.selling_price" type="number" step="0.01" min="0" class="field" placeholder="Sell price" aria-label="Selling price" required />
                            <input v-model="d.cost_price" type="number" step="0.01" min="0" class="field" placeholder="Cost" aria-label="Cost price" />
                            <select v-model="d.status" class="field" aria-label="Inspection" :disabled="!auth.can('used.inspect')"><option value="pending_inspection">Not inspected</option><option value="available">Passed</option><option value="rejected">Rejected</option></select>
                            <button type="button" class="btn btn-ghost text-bad" aria-label="Remove tyre" @click="l.unit_details.splice(j, 1)"><Trash2 class="size-4" /></button>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" @click="l.unit_details.push(blankUnit(l.unit_details[0]?.selling_price))"><Plus class="size-4" /> Add another tyre</button>
                    </div>
                </div>
                <p v-if="!lines.length" class="border-t border-line-soft px-5 py-8 text-center text-sm text-muted">Search above and add each tyre in the delivery.</p>
            </section>

            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary" :disabled="!lines.length || !form.supplier_id || submit.busy.value"><CircleCheck class="size-5" /> Review and post</button>
                <button type="button" class="btn btn-secondary" :disabled="!lines.length || !form.supplier_id || submit.busy.value" @click="saveDraft">Save draft</button>
            </div>
        </form>

        <ModalDialog :open="reviewing" title="Check the delivery" wide @close="reviewing = false">
            <p class="mb-3 text-sm text-muted">{{ supplierName }} · {{ form.supplier_reference || 'no reference' }} · {{ form.delivery_date.split('-').reverse().join('/') }}</p>
            <table class="data-table">
                <thead><tr><th>Tyre</th><th>Location</th><th class="num">Qty</th><th class="num">Stock after</th></tr></thead>
                <tbody><tr v-for="l in lines" :key="l.product_id"><td>{{ l.name }} <SizeChip :size="l.size" /></td><td>{{ locationName(l.location_id) }}</td><td class="num font-semibold">{{ qty(l) }}</td><td class="num">{{ l.current + qty(l) }}</td></tr></tbody>
            </table>
            <p class="mt-3 text-right text-sm">{{ totalQty }} tyres · approx. {{ formatMoney(totalCost) }} at cost</p>
            <div v-if="duplicateRef" class="mt-3 rounded-[8px] bg-warn-soft p-3 text-sm text-warn" role="alert">{{ duplicateRef }}</div>
            <template #footer>
                <button type="button" class="btn btn-secondary" @click="reviewing = false">Back</button>
                <button v-if="duplicateRef" type="button" class="btn btn-dark" :disabled="submit.busy.value" @click="confirmPost(true)">Post anyway – different delivery</button>
                <button v-else type="button" class="btn btn-primary" :disabled="submit.busy.value || !auth.can('receiving.post')" @click="confirmPost()">{{ submit.busy.value ? 'Posting…' : 'Post delivery' }}</button>
            </template>
        </ModalDialog>
    </div>
</template>
