<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { Printer, Ban, Undo2, CircleCheck } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import { get, post, type ApiError } from '@/api/http';
import { useAuth } from '@/stores/auth';
import { useLookups } from '@/stores/lookups';
import { useSubmit } from '@/composables/useForm';
import { formatDateTime, formatMoney, label } from '@/utils/format';

const route = useRoute();
const auth = useAuth();
const lookups = useLookups();
const sale = ref<any>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const submit = useSubmit();

async function load() {
    loading.value = true;
    try {
        sale.value = (await get(`/sales/${route.params.id}`)).data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
const printPage = () => window.print();

const voidOpen = ref(false);
const voidReason = ref('');
async function voidSale() {
    if (await submit.run(() => post(`/sales/${route.params.id}/void`, { reason: voidReason.value }), 'Sale voided. Stock restored.')) {
        voidOpen.value = false;
        load();
    }
}

const returnOpen = ref(false);
const ret = reactive<{ reason: string; qty: Record<number, number> }>({ reason: '', qty: {} });
const returnable = computed(() => (sale.value?.items ?? []).filter((i: any) => i.item_type === 'product' && i.quantity > (i.returned_qty ?? 0)));
async function recordReturn() {
    const items = Object.entries(ret.qty).filter(([, q]) => q > 0).map(([id, q]) => ({ sale_item_id: Number(id), quantity: q }));
    if (await submit.run((key) => post(`/sales/${route.params.id}/returns`, { reason: ret.reason, items, idempotency_key: key }), 'Return recorded. Tyres are in quarantine until inspected.')) {
        returnOpen.value = false;
        load();
    }
}

const payment = reactive({ payment_method: 'cash', payment_reference: '' });
async function completeDraft() {
    if (await submit.run(() => post(`/sales/${route.params.id}/complete`, payment), 'Sale completed. Stock deducted.')) load();
}
</script>

<template>
    <AsyncState :loading="loading" :error="error" @retry="load">
        <div v-if="sale" class="max-w-4xl">
            <PageHeader :title="`Sale ${sale.sale_no}`" back="/sales">
                <template #meta><div class="mt-2 flex flex-wrap items-center gap-2"><StatusBadge :status="sale.status" /><StatusBadge v-if="sale.status !== 'draft'" :status="sale.payment_status" /><span class="text-sm text-muted">{{ formatDateTime(sale.completed_at ?? sale.created_at) }} · {{ sale.user }}</span></div></template>
                <template #actions>
                    <button type="button" class="btn btn-secondary" @click="printPage"><Printer class="size-4" /> Print</button>
                    <button v-if="['completed', 'partially_returned'].includes(sale.status) && auth.can('sales.returns')" type="button" class="btn btn-secondary" @click="returnOpen = true"><Undo2 class="size-4" /> Customer return</button>
                    <button v-if="sale.status === 'completed' && auth.can('sales.void')" type="button" class="btn btn-ghost text-bad" @click="voidOpen = true"><Ban class="size-4" /> Void</button>
                </template>
            </PageHeader>

            <section v-if="sale.status === 'draft'" class="panel mb-5 p-5">
                <h2 class="panel-title mb-2">Complete this draft</h2>
                <p class="mb-3 text-sm text-muted">Stock is checked and deducted when you complete.</p>
                <div class="flex flex-wrap items-end gap-2">
                    <label><span class="field-label">Payment</span><select v-model="payment.payment_method" class="field"><option value="cash">Cash</option><option value="card">Card</option><option value="eft">EFT</option></select></label>
                    <label><span class="field-label">Reference</span><input v-model="payment.payment_reference" class="field" /></label>
                    <button type="button" class="btn btn-primary" :disabled="submit.busy.value" @click="completeDraft"><CircleCheck class="size-4" /> Complete sale</button>
                </div>
            </section>
            <p v-if="sale.status === 'voided'" class="mb-4 rounded-[8px] bg-bad-soft px-4 py-3 text-sm text-bad">Voided {{ formatDateTime(sale.voided_at) }} by {{ sale.voided_by }}: {{ sale.void_reason }}</p>

            <article class="panel p-5 sm:p-8">
                <header class="mb-6 flex flex-wrap justify-between gap-4">
                    <div class="flex gap-4">
                        <img v-if="lookups.settings?.logo_url" :src="lookups.settings.logo_url" alt="" class="size-20 shrink-0 rounded-full object-contain" />
                        <div>
                        <p class="font-display text-2xl font-bold">{{ lookups.settings?.business_name }}</p>
                        <p class="text-sm whitespace-pre-line text-muted">{{ lookups.settings?.business_address }}</p>
                        <p class="text-sm text-muted">{{ lookups.settings?.business_phone }}</p>
                        <p v-if="sale.vat_registered && lookups.settings?.vat_number" class="text-sm text-muted">VAT no. {{ lookups.settings.vat_number }}</p>
                        </div>
                    </div>
                    <div class="text-right text-sm">
                        <p class="font-display text-xl font-semibold">{{ sale.vat_registered ? 'Tax invoice' : 'Sale' }} {{ sale.sale_no }}</p>
                        <p>{{ formatDateTime(sale.completed_at ?? sale.created_at) }}</p>
                        <p v-if="sale.customer_name" class="mt-2 font-semibold">{{ sale.customer_name }}</p>
                        <p v-if="sale.customer_phone">{{ sale.customer_phone }}</p>
                        <p v-if="sale.vehicle">{{ sale.vehicle }}</p>
                    </div>
                </header>
                <table class="data-table">
                    <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Discount</th><th class="num">Total</th></tr></thead>
                    <tbody>
                        <tr v-for="i in sale.items" :key="i.id">
                            <td>{{ i.description }}<div v-if="i.returned_qty" class="text-[12.5px] text-warn">{{ i.returned_qty }} returned</div><div v-if="i.location" class="no-print text-[12px] text-muted">from {{ i.location }}</div></td>
                            <td class="num">{{ i.quantity }}</td>
                            <td class="num">{{ formatMoney(i.unit_price) }}</td>
                            <td class="num">{{ Number(i.discount) ? formatMoney(i.discount) : '–' }}</td>
                            <td class="num font-semibold">{{ formatMoney(i.line_total) }}</td>
                        </tr>
                    </tbody>
                </table>
                <dl class="mt-4 ml-auto max-w-xs space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd>{{ formatMoney(sale.subtotal) }}</dd></div>
                    <div v-if="Number(sale.discount_total)" class="flex justify-between"><dt class="text-muted">Discounts</dt><dd>−{{ formatMoney(sale.discount_total) }}</dd></div>
                    <div v-if="sale.vat_registered" class="flex justify-between"><dt class="text-muted">VAT {{ sale.vat_rate }}% {{ sale.prices_include_vat ? 'included' : '' }}</dt><dd>{{ formatMoney(sale.vat_amount) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 text-base font-bold"><dt>Total</dt><dd>{{ formatMoney(sale.total) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Paid ({{ (sale.payment_method ?? '–').toUpperCase() }})</dt><dd>{{ formatMoney(sale.amount_paid) }}</dd></div>
                    <div v-if="sale.cost_total !== undefined" class="no-print flex justify-between text-muted"><dt>Cost of tyres (FIFO)</dt><dd>{{ formatMoney(sale.cost_total) }}</dd></div>
                </dl>
                <p v-if="sale.notes" class="mt-4 text-sm">Notes: {{ sale.notes }}</p>
            </article>

            <section v-if="sale.returns?.length" class="panel mt-5">
                <div class="panel-head"><h2 class="panel-title">Returns</h2></div>
                <ul class="divide-y divide-line-soft text-sm">
                    <li v-for="r in sale.returns" :key="r.id" class="px-5 py-3">
                        <span class="font-semibold">{{ r.return_no }}</span> · {{ formatDateTime(r.created_at) }} · {{ r.reason }} · refund {{ formatMoney(r.refund_amount) }}
                        <span v-for="ri in r.items" :key="ri.id" class="ml-2"><StatusBadge :status="ri.outcome" :text="`${ri.quantity} ${label(ri.outcome).toLowerCase()}`" /></span>
                    </li>
                </ul>
            </section>

            <ModalDialog :open="voidOpen" title="Void sale" @close="voidOpen = false">
                <p class="mb-3 text-sm text-muted">Use void only for a sale captured in error where the tyres never left. The tyres go back into stock at their original location. For tyres a customer brings back, record a return instead.</p>
                <label for="void-reason" class="field-label">Reason</label>
                <textarea id="void-reason" v-model="voidReason" rows="3" class="field" />
                <template #footer>
                    <button type="button" class="btn btn-secondary" @click="voidOpen = false">Cancel</button>
                    <button type="button" class="btn btn-danger" :disabled="voidReason.length < 5 || submit.busy.value" @click="voidSale">Void sale</button>
                </template>
            </ModalDialog>

            <ModalDialog :open="returnOpen" title="Customer return" @close="returnOpen = false">
                <p class="mb-3 text-sm text-muted">Returned tyres go to the quarantine area. They are only sellable again after inspection.</p>
                <div v-for="i in returnable" :key="i.id" class="mb-2 flex items-center justify-between gap-3">
                    <span class="text-sm">{{ i.description }} <span class="text-muted">(max {{ i.quantity - (i.returned_qty ?? 0) }})</span></span>
                    <input v-model.number="ret.qty[i.id]" type="number" min="0" :max="i.quantity - (i.returned_qty ?? 0)" class="field !w-24" :aria-label="`Quantity returned of ${i.description}`" />
                </div>
                <label for="ret-reason" class="field-label mt-3">Reason</label>
                <textarea id="ret-reason" v-model="ret.reason" rows="2" class="field" />
                <template #footer>
                    <button type="button" class="btn btn-secondary" @click="returnOpen = false">Cancel</button>
                    <button type="button" class="btn btn-primary" :disabled="ret.reason.length < 3 || submit.busy.value" @click="recordReturn">Record return</button>
                </template>
            </ModalDialog>
        </div>
    </AsyncState>
</template>
