<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Printer, MessageCircle, CalendarClock, ShoppingCart, XCircle } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import { get, post, type ApiError } from '@/api/http';
import { useAuth } from '@/stores/auth';
import { useLookups } from '@/stores/lookups';
import { useSubmit } from '@/composables/useForm';
import { formatDate, formatMoney } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const lookups = useLookups();
const q = ref<any>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const submit = useSubmit();
const saleOpen = ref(false);
const payment = reactive({ payment_method: 'cash', payment_reference: '' });

async function load() {
    loading.value = true;
    try {
        q.value = (await get(`/quotations/${route.params.id}`)).data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
const printPage = () => window.print();

async function toReservation() {
    const res = await submit.run((key) => post(`/quotations/${route.params.id}/reservation`, { idempotency_key: key }), 'Reservation created from the quotation.');
    if (res) router.push(`/reservations/${res.data.reservation_id}`);
}
async function toSale() {
    const res = await submit.run((key) => post(`/quotations/${route.params.id}/sale`, { ...payment, idempotency_key: key }), 'Sale completed from the quotation.');
    if (res) router.push(`/sales/${res.data.id}`);
}
async function cancel() {
    if (!confirm('Cancel this quotation?')) return;
    if (await submit.run(() => post(`/quotations/${route.params.id}/cancel`), 'Quotation cancelled.')) load();
}
</script>

<template>
    <AsyncState :loading="loading" :error="error" @retry="load">
        <div v-if="q" class="max-w-4xl">
            <PageHeader :title="`Quotation ${q.quotation_no}`" back="/quotations">
                <template #meta><div class="mt-2"><StatusBadge :status="q.status" /></div></template>
                <template #actions>
                    <a :href="q.whatsapp_url" target="_blank" rel="noopener" class="btn btn-primary"><MessageCircle class="size-4" /> Send on WhatsApp</a>
                    <button type="button" class="btn btn-secondary" @click="printPage"><Printer class="size-4" /> Print / PDF</button>
                    <template v-if="q.status === 'open'">
                        <button v-if="auth.can('reservations.manage')" type="button" class="btn btn-secondary" :disabled="submit.busy.value" @click="toReservation"><CalendarClock class="size-4" /> Reserve</button>
                        <button v-if="auth.can('sales.create')" type="button" class="btn btn-secondary" @click="saleOpen = true"><ShoppingCart class="size-4" /> Convert to sale</button>
                        <button type="button" class="btn btn-ghost text-bad" @click="cancel"><XCircle class="size-4" /> Cancel</button>
                    </template>
                </template>
            </PageHeader>
            <p v-if="!q.customer_phone" class="no-print mb-3 text-sm text-muted">No customer phone on this quotation – WhatsApp opens so you can choose the contact.</p>

            <article class="panel p-5 sm:p-8">
                <header class="mb-6 flex flex-wrap justify-between gap-4">
                    <div>
                        <img v-if="lookups.settings?.logo_url" :src="lookups.settings.logo_url" alt="" class="mb-2 size-20 rounded-full object-contain" />
                        <p class="font-display text-2xl font-bold">{{ lookups.settings?.business_name }}</p>
                        <p class="text-sm whitespace-pre-line text-muted">{{ lookups.settings?.business_address }}</p>
                        <p class="text-sm text-muted">WhatsApp / phone {{ lookups.settings?.business_phone }}</p>
                    </div>
                    <div class="text-right text-sm">
                        <p class="font-display text-xl font-semibold">Quotation {{ q.quotation_no }}</p>
                        <p>Date {{ formatDate(q.created_at) }}</p>
                        <p>Valid until {{ formatDate(q.valid_until) }}</p>
                        <p v-if="q.customer_name" class="mt-2 font-semibold">{{ q.customer_name }}</p>
                        <p>{{ q.customer_phone }}</p>
                        <p>{{ q.vehicle }}</p>
                    </div>
                </header>
                <table class="data-table">
                    <thead><tr><th>Description</th><th>Size</th><th class="num">Qty</th><th class="num">Price each</th><th class="num">Total</th></tr></thead>
                    <tbody>
                        <tr v-for="i in q.items" :key="i.id">
                            <td>{{ i.description }}</td><td>{{ i.size_display ?? '' }}</td><td class="num">{{ i.quantity }}</td><td class="num">{{ formatMoney(i.unit_price) }}</td><td class="num font-semibold">{{ formatMoney(i.line_total) }}</td>
                        </tr>
                    </tbody>
                </table>
                <dl class="mt-4 ml-auto max-w-xs space-y-1 text-sm">
                    <div v-if="Number(q.discount_total)" class="flex justify-between"><dt class="text-muted">Discount</dt><dd>−{{ formatMoney(q.discount_total) }}</dd></div>
                    <div v-if="q.vat_registered" class="flex justify-between"><dt class="text-muted">VAT {{ q.vat_rate }}% {{ q.prices_include_vat ? 'included' : '' }}</dt><dd>{{ formatMoney(q.vat_amount) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 text-lg font-bold"><dt>Total</dt><dd>{{ formatMoney(q.total) }}</dd></div>
                </dl>
                <p class="mt-6 text-[13px] text-muted">Prices are valid until the date shown and subject to stock availability at the time of fitting. {{ q.notes }}</p>
            </article>

            <ModalDialog :open="saleOpen" title="Convert to sale" @close="saleOpen = false">
                <p class="mb-3 text-sm text-muted">The quoted prices are used. Stock is checked and deducted now.</p>
                <div class="grid grid-cols-2 gap-3">
                    <label><span class="field-label">Payment</span><select v-model="payment.payment_method" class="field"><option value="cash">Cash</option><option value="card">Card</option><option value="eft">EFT</option></select></label>
                    <label><span class="field-label">Reference</span><input v-model="payment.payment_reference" class="field" /></label>
                </div>
                <template #footer>
                    <button type="button" class="btn btn-secondary" @click="saleOpen = false">Cancel</button>
                    <button type="button" class="btn btn-primary" :disabled="submit.busy.value" @click="toSale">Complete sale</button>
                </template>
            </ModalDialog>
        </div>
    </AsyncState>
</template>
