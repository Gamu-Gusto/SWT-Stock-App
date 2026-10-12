<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { Pencil, Archive, RotateCcw } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import FormField from '@/components/ui/FormField.vue';
import { get, put, type ApiError } from '@/api/http';
import { useAuth } from '@/stores/auth';
import { useSubmit } from '@/composables/useForm';
import { formatDate, formatMoney } from '@/utils/format';

const route = useRoute();
const auth = useAuth();
const s = ref<any>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const edit = ref(false);
const form = reactive<any>({});
const submit = useSubmit();

async function load() {
    loading.value = true;
    try {
        s.value = (await get(`/suppliers/${route.params.id}`)).data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

function openEdit() {
    Object.assign(form, { company_name: s.value.company_name, contact_person: s.value.contact_person, phone: s.value.phone, email: s.value.email, address: s.value.address, notes: s.value.notes });
    edit.value = true;
}
async function save(extra: object = {}) {
    if (await submit.run(() => put(`/suppliers/${route.params.id}`, { ...form, ...extra }), 'Supplier saved.')) {
        edit.value = false;
        load();
    }
}
async function toggleArchive() {
    Object.assign(form, { company_name: s.value.company_name });
    save({ status: s.value.status === 'active' ? 'archived' : 'active' });
}
</script>

<template>
    <AsyncState :loading="loading" :error="error" @retry="load">
        <div v-if="s">
            <PageHeader :title="s.company_name" back="/suppliers">
                <template #meta><div class="mt-2"><StatusBadge :status="s.status" /></div></template>
                <template #actions>
                    <template v-if="auth.can('suppliers.manage')">
                        <button type="button" class="btn btn-secondary" @click="openEdit"><Pencil class="size-4" /> Edit</button>
                        <button type="button" class="btn btn-ghost" @click="toggleArchive"><component :is="s.status === 'active' ? Archive : RotateCcw" class="size-4" /> {{ s.status === 'active' ? 'Archive' : 'Re-activate' }}</button>
                    </template>
                </template>
            </PageHeader>
            <p class="mb-4 text-sm text-muted">Suppliers with delivery history are archived, never deleted, so past receipts stay traceable.</p>

            <div class="mb-5 grid gap-5 lg:grid-cols-3">
                <section class="panel p-5 text-sm">
                    <h2 class="panel-title mb-3">Contact</h2>
                    <p>{{ s.contact_person ?? '–' }}</p><p>{{ s.phone }}</p><p>{{ s.email }}</p><p class="mt-2 text-muted">{{ s.address }}</p><p v-if="s.notes" class="mt-2">{{ s.notes }}</p>
                </section>
                <div class="grid grid-cols-3 overflow-hidden rounded-[10px] border border-line bg-panel lg:col-span-2">
                    <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Deliveries</p><p class="qty !text-3xl">{{ s.totals.deliveries }}</p></div>
                    <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Tyres supplied</p><p class="qty !text-3xl">{{ s.totals.quantity }}</p></div>
                    <div class="p-4"><p class="text-sm text-muted">Purchase cost</p><p class="qty !text-2xl">{{ s.totals.cost !== null ? formatMoney(s.totals.cost) : '–' }}</p></div>
                </div>
            </div>

            <section class="panel mb-5 overflow-x-auto">
                <div class="panel-head"><h2 class="panel-title">Deliveries</h2></div>
                <table class="data-table">
                    <thead><tr><th>Receipt</th><th>Date</th><th>Reference</th><th class="num">Tyres</th><th v-if="auth.can('financials.view')" class="num">Cost</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr v-for="r in s.receipts" :key="r.id"><td><RouterLink :to="`/receiving/${r.id}`" class="font-semibold hover:underline">{{ r.receipt_no }}</RouterLink></td><td>{{ formatDate(r.delivery_date) }}</td><td>{{ r.supplier_reference ?? '–' }}</td><td class="num">{{ r.total_quantity }}</td><td v-if="auth.can('financials.view')" class="num">{{ formatMoney(r.total_cost) }}</td><td><StatusBadge :status="r.status" /></td></tr>
                        <tr v-if="!s.receipts.length"><td colspan="6" class="py-6 text-center text-muted">No deliveries yet.</td></tr>
                    </tbody>
                </table>
            </section>
            <section class="panel overflow-x-auto">
                <div class="panel-head"><h2 class="panel-title">Tyres supplied</h2></div>
                <table class="data-table">
                    <thead><tr><th>Tyre</th><th>Size</th><th>Condition</th><th class="num">Quantity</th><th>Last delivery</th><th v-if="auth.can('financials.view')" class="num">Last cost</th></tr></thead>
                    <tbody>
                        <tr v-for="p in s.products" :key="p.id"><td><RouterLink :to="`/inventory/${p.id}`" class="font-semibold hover:underline">{{ p.brand }} {{ p.model }}</RouterLink></td><td>{{ p.size }}</td><td>{{ p.item_condition }}</td><td class="num">{{ p.qty }}</td><td>{{ formatDate(p.last_delivery) }}</td><td v-if="auth.can('financials.view')" class="num">{{ formatMoney(p.last_unit_cost) }}</td></tr>
                        <tr v-if="!s.products.length"><td colspan="6" class="py-6 text-center text-muted">Nothing received yet.</td></tr>
                    </tbody>
                </table>
            </section>

            <ModalDialog :open="edit" title="Edit supplier" @close="edit = false">
                <form id="sup-edit" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save()">
                    <div class="sm:col-span-2"><FormField v-slot="{ id }" label="Company name" required :error="submit.fieldError('company_name')"><input :id="id" v-model="form.company_name" class="field" required /></FormField></div>
                    <FormField v-slot="{ id }" label="Contact person"><input :id="id" v-model="form.contact_person" class="field" /></FormField>
                    <FormField v-slot="{ id }" label="Telephone"><input :id="id" v-model="form.phone" class="field" /></FormField>
                    <FormField v-slot="{ id }" label="E-mail" :error="submit.fieldError('email')"><input :id="id" v-model="form.email" type="email" class="field" /></FormField>
                    <FormField v-slot="{ id }" label="Address"><input :id="id" v-model="form.address" class="field" /></FormField>
                    <div class="sm:col-span-2"><FormField v-slot="{ id }" label="Notes"><textarea :id="id" v-model="form.notes" rows="2" class="field" /></FormField></div>
                </form>
                <template #footer><button type="button" class="btn btn-secondary" @click="edit = false">Cancel</button><button type="submit" form="sup-edit" class="btn btn-primary">Save</button></template>
            </ModalDialog>
        </div>
    </AsyncState>
</template>
