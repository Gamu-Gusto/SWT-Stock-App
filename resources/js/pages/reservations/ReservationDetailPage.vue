<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ShoppingCart, XCircle, Clock, MessageCircle } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { get, post, type ApiError } from '@/api/http';
import { useAuth } from '@/stores/auth';
import { useLookups } from '@/stores/lookups';
import { useSubmit } from '@/composables/useForm';
import { formatDateTime, formatMoney, relativeTime } from '@/utils/format';

const route = useRoute();
const auth = useAuth();
const lookups = useLookups();
const r = ref<any>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const submit = useSubmit();

async function load() {
    loading.value = true;
    try {
        r.value = (await get(`/reservations/${route.params.id}`)).data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function cancel() {
    const reason = prompt('Why is the reservation being cancelled?');
    if (reason === null) return;
    const res = await submit.run(() => post(`/reservations/${route.params.id}/cancel`, { reason }), 'Reservation cancelled. Tyres released.');
    if (res) r.value = res.data;
}
async function extend(hours: number) {
    const res = await submit.run(() => post(`/reservations/${route.params.id}/extend`, { hours }), `Extended by ${hours} hours.`);
    if (res) r.value = res.data;
}
function whatsapp() {
    const digits = String(r.value.customer_phone).replace(/\D/g, '').replace(/^0(\d{9})$/, '27$1');
    const text = `Hi ${r.value.customer_name}, ${lookups.settings?.business_name} is holding ${r.value.items.map((i: any) => `${i.quantity} × ${i.product}`).join(', ')} for you until ${formatDateTime(r.value.expires_at)} (ref ${r.value.reservation_no}).`;
    window.open(`https://wa.me/${digits}?text=${encodeURIComponent(text)}`, '_blank', 'noopener');
}
</script>

<template>
    <AsyncState :loading="loading" :error="error" @retry="load">
        <div v-if="r" class="max-w-3xl">
            <PageHeader :title="r.customer_name" :subtitle="`Reservation ${r.reservation_no} · ${r.customer_phone}`" back="/reservations">
                <template #meta><div class="mt-2"><StatusBadge :status="r.status" /></div></template>
                <template #actions>
                    <template v-if="r.status === 'active'">
                        <RouterLink v-if="auth.can('sales.create')" :to="`/sales/new?reservation_id=${r.id}`" class="btn btn-primary"><ShoppingCart class="size-4" /> Sell reserved tyres</RouterLink>
                        <button type="button" class="btn btn-secondary" @click="whatsapp"><MessageCircle class="size-4" /> WhatsApp customer</button>
                        <button type="button" class="btn btn-ghost text-bad" :disabled="submit.busy.value" @click="cancel"><XCircle class="size-4" /> Cancel</button>
                    </template>
                </template>
            </PageHeader>

            <section v-if="r.status === 'active'" class="panel mb-5 flex flex-wrap items-center gap-3 p-4">
                <Clock class="size-5" :class="r.is_expiring_soon ? 'text-warn' : 'text-muted'" />
                <span class="flex-1">Held until <strong>{{ formatDateTime(r.expires_at) }}</strong> ({{ relativeTime(r.expires_at) }}). It is released automatically after that.</span>
                <button type="button" class="btn btn-secondary btn-sm" @click="extend(24)">+24 hours</button>
                <button type="button" class="btn btn-secondary btn-sm" @click="extend(72)">+3 days</button>
            </section>

            <section class="panel mb-5">
                <table class="data-table">
                    <thead><tr><th>Tyre</th><th class="num">Reserved</th><th class="num">Price each</th></tr></thead>
                    <tbody>
                        <tr v-for="i in r.items" :key="i.id">
                            <td><RouterLink :to="`/inventory/${i.product_id}`" class="font-semibold hover:underline">{{ i.product }}</RouterLink> <span v-if="i.unit_code" class="text-muted">{{ i.unit_code }}</span></td>
                            <td class="num">{{ i.quantity }}</td>
                            <td class="num">{{ formatMoney(i.unit_price) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <dl class="panel grid gap-3 p-5 text-sm sm:grid-cols-2">
                <div><dt class="text-muted">Created</dt><dd>{{ formatDateTime(r.created_at) }} by {{ r.user }}</dd></div>
                <div v-if="r.notes"><dt class="text-muted">Notes</dt><dd>{{ r.notes }}</dd></div>
                <div v-if="r.sale_id"><dt class="text-muted">Sale</dt><dd><RouterLink :to="`/sales/${r.sale_id}`" class="link">View sale</RouterLink></dd></div>
                <div v-if="r.cancelled_at"><dt class="text-muted">{{ r.status === 'expired' ? 'Expired' : 'Cancelled' }}</dt><dd>{{ formatDateTime(r.cancelled_at) }} {{ r.cancelled_by ? `by ${r.cancelled_by}` : '' }} – {{ r.cancel_reason }}</dd></div>
            </dl>
        </div>
    </AsyncState>
</template>
