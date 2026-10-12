<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Banknote, CreditCard, Landmark, CircleCheck } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import FormField from '@/components/ui/FormField.vue';
import CartEditor from '@/components/sales/CartEditor.vue';
import TotalsPanel from '@/components/sales/TotalsPanel.vue';
import { get, post } from '@/api/http';
import { useSubmit } from '@/composables/useForm';
import type { CartLine, ServiceLine } from '@/types/cart';
import type { Product } from '@/types';

const route = useRoute();
const router = useRouter();
const submit = useSubmit();
const cart = ref<InstanceType<typeof CartEditor> | null>(null);
const lines = ref<CartLine[]>([]);
const services = ref<ServiceLine[]>([]);
const reservation = ref<any>(null);
const form = reactive({ customer_name: '', customer_phone: '', customer_email: '', vehicle: '', notes: '', payment_method: '' as '' | 'cash' | 'card' | 'eft', payment_reference: '', amount_paid: '' });

onMounted(async () => {
    if (route.query.product_id) {
        const p = (await get<{ data: Product }>(`/products/${route.query.product_id}`)).data;
        if (p.tracking === 'quantity') lines.value.push(cart.value!.lineFromProduct(p));
    }
    if (route.query.reservation_id) {
        reservation.value = (await get(`/reservations/${route.query.reservation_id}`)).data;
        Object.assign(form, { customer_name: reservation.value.customer_name, customer_phone: reservation.value.customer_phone });
        for (const item of reservation.value.items) {
            const p = (await get<{ data: Product }>(`/products/${item.product_id}`)).data;
            const line = cart.value!.lineFromProduct(p);
            Object.assign(line, {
                key: item.tyre_unit_id ? `u${item.tyre_unit_id}` : `p${p.id}`,
                tyre_unit_id: item.tyre_unit_id ?? undefined,
                unit_code: item.unit_code ?? undefined,
                quantity: item.quantity - item.fulfilled_qty,
                unit_price: item.unit_price,
                reservation_item_id: item.id,
                available: p.qty_available + item.quantity,
            });
            lines.value.push(line);
        }
    }
});

const stockProblem = computed(() => lines.value.some((l) => l.quantity > l.available || l.quantity < 1));
const canSubmit = computed(() => (lines.value.length || services.value.length) && form.payment_method && !stockProblem.value && !submit.busy.value);

async function complete(status: 'completed' | 'draft' = 'completed') {
    const body = {
        ...form,
        status,
        payment_method: form.payment_method || null,
        amount_paid: form.amount_paid || null,
        reservation_id: reservation.value?.id ?? null,
        items: lines.value.map((l) => ({
            product_id: l.product_id, tyre_unit_id: l.tyre_unit_id ?? null, quantity: l.quantity,
            unit_price: l.unit_price, discount: l.discount || null, reservation_item_id: l.reservation_item_id ?? null,
        })),
        services: services.value.map((s) => ({ service_code: s.service_code, description: s.description, quantity: s.quantity, unit_price: s.unit_price || '0', discount: s.discount || null })),
    };
    const res = await submit.run((key) => post('/sales', { ...body, idempotency_key: key }), status === 'draft' ? 'Draft sale saved. Stock has not changed.' : 'Sale completed. Stock deducted.');
    if (res) router.replace(`/sales/${res.data.id}`);
}

const methods = [
    { v: 'cash', l: 'Cash', icon: Banknote },
    { v: 'card', l: 'Card', icon: CreditCard },
    { v: 'eft', l: 'EFT', icon: Landmark },
] as const;
</script>

<template>
    <div>
        <PageHeader title="New sale" back="/sales" :subtitle="reservation ? `Completing reservation ${reservation.reservation_no} for ${reservation.customer_name}. Reserved tyres are not deducted twice.` : 'Add the tyres and services, take payment, then complete the sale. Stock is deducted once, when you complete.'" />
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
            <div class="space-y-5">
                <section class="panel p-4 sm:p-5">
                    <h2 class="panel-title mb-3">Tyres and services</h2>
                    <CartEditor ref="cart" :lines="lines" :services="services" :error-for="submit.fieldError" check-stock :locked-lines="!!reservation" />
                </section>
                <section class="panel p-4 sm:p-5">
                    <h2 class="panel-title mb-3">Customer <span class="text-sm font-normal text-muted">(optional)</span></h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <FormField v-slot="{ id }" label="Name" :error="submit.fieldError('customer_name')"><input :id="id" v-model="form.customer_name" class="field" autocomplete="off" /></FormField>
                        <FormField v-slot="{ id }" label="Phone" :error="submit.fieldError('customer_phone')"><input :id="id" v-model="form.customer_phone" type="tel" class="field" autocomplete="off" /></FormField>
                        <FormField v-slot="{ id }" label="E-mail" :error="submit.fieldError('customer_email')"><input :id="id" v-model="form.customer_email" type="email" class="field" autocomplete="off" /></FormField>
                        <FormField v-slot="{ id }" label="Vehicle / registration"><input :id="id" v-model="form.vehicle" class="field" autocomplete="off" /></FormField>
                        <div class="sm:col-span-2"><FormField v-slot="{ id }" label="Notes"><input :id="id" v-model="form.notes" class="field" /></FormField></div>
                    </div>
                </section>
            </div>

            <aside class="lg:sticky lg:top-20 lg:self-start">
                <section class="panel p-4 sm:p-5">
                    <h2 class="panel-title mb-3">Payment</h2>
                    <fieldset>
                        <legend class="sr-only">Payment method</legend>
                        <div class="grid grid-cols-3 gap-2">
                            <label v-for="m in methods" :key="m.v" class="flex min-h-14 cursor-pointer flex-col items-center justify-center gap-1 rounded-[8px] border text-sm font-semibold" :class="form.payment_method === m.v ? 'border-asphalt bg-asphalt text-white' : 'border-line hover:bg-surface'">
                                <input v-model="form.payment_method" type="radio" name="payment" :value="m.v" class="sr-only" />
                                <component :is="m.icon" class="size-5" /> {{ m.l }}
                            </label>
                        </div>
                    </fieldset>
                    <p v-if="submit.fieldError('payment_method')" class="field-error">{{ submit.fieldError('payment_method') }}</p>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <FormField v-slot="{ id }" label="Amount paid" hint="Blank = paid in full"><input :id="id" v-model="form.amount_paid" type="number" step="0.01" min="0" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Payment ref."><input :id="id" v-model="form.payment_reference" class="field" placeholder="Card slip / EFT ref" /></FormField>
                    </div>
                    <div class="mt-4"><TotalsPanel :lines="lines" :services="services" /></div>
                    <p class="mt-2 text-[12.5px] text-muted">The server confirms the final total when you complete the sale.</p>
                    <button type="button" class="btn btn-primary mt-4 w-full !min-h-14 text-base" :disabled="!canSubmit" @click="complete()">
                        <CircleCheck class="size-5" /> {{ submit.busy.value ? 'Completing…' : 'Complete sale' }}
                    </button>
                    <button type="button" class="btn btn-ghost mt-2 w-full" :disabled="!lines.length && !services.length" @click="complete('draft')">Save as draft</button>
                    <p v-if="stockProblem" class="field-error text-center">Reduce quantities to what is available.</p>
                </section>
            </aside>
        </div>
    </div>
</template>
