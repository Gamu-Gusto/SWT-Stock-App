<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
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
const form = reactive({ customer_name: '', customer_phone: '', customer_email: '', vehicle: '', valid_until: '', notes: '' });

onMounted(async () => {
    if (route.query.product_id) {
        const p = (await get<{ data: Product }>(`/products/${route.query.product_id}`)).data;
        if (p.tracking === 'quantity') {
            const line = cart.value!.lineFromProduct(p);
            line.quantity = Math.min(4, Math.max(1, p.qty_available || 4));
            lines.value.push(line);
        }
    }
});

async function save() {
    const res = await submit.run((key) => post('/quotations', {
        ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v])),
        idempotency_key: key,
        items: lines.value.map((l) => ({ product_id: l.product_id, tyre_unit_id: l.tyre_unit_id ?? null, quantity: l.quantity, unit_price: l.unit_price, discount: l.discount || null })),
        services: services.value.map((s) => ({ service_code: s.service_code, description: s.description, quantity: s.quantity, unit_price: s.unit_price || '0', discount: s.discount || null })),
    }), 'Quotation created.');
    if (res) router.replace(`/quotations/${res.data.id}`);
}
</script>

<template>
    <div>
        <PageHeader title="New quotation" back="/quotations" subtitle="Quote tyres and services. Stock is not reserved or deducted – convert the quote when the customer accepts." />
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div class="space-y-5">
                <section class="panel p-5"><h2 class="panel-title mb-3">Tyres and services</h2><CartEditor ref="cart" :lines="lines" :services="services" /></section>
                <section class="panel grid gap-3 p-5 sm:grid-cols-2">
                    <h2 class="panel-title sm:col-span-2">Customer</h2>
                    <FormField v-slot="{ id }" label="Name"><input :id="id" v-model="form.customer_name" class="field" /></FormField>
                    <FormField v-slot="{ id }" label="Phone (for WhatsApp)" :error="submit.fieldError('customer_phone')"><input :id="id" v-model="form.customer_phone" type="tel" class="field" placeholder="082 123 4567" /></FormField>
                    <FormField v-slot="{ id }" label="E-mail" :error="submit.fieldError('customer_email')"><input :id="id" v-model="form.customer_email" type="email" class="field" /></FormField>
                    <FormField v-slot="{ id }" label="Vehicle"><input :id="id" v-model="form.vehicle" class="field" /></FormField>
                    <FormField v-slot="{ id }" label="Valid until" hint="Blank = default validity" :error="submit.fieldError('valid_until')"><input :id="id" v-model="form.valid_until" type="date" class="field" /></FormField>
                    <FormField v-slot="{ id }" label="Notes"><input :id="id" v-model="form.notes" class="field" /></FormField>
                </section>
            </div>
            <aside class="lg:sticky lg:top-20 lg:self-start">
                <section class="panel p-5">
                    <TotalsPanel :lines="lines" :services="services" />
                    <button type="button" class="btn btn-primary mt-4 w-full" :disabled="(!lines.length && !services.length) || submit.busy.value" @click="save">Create quotation</button>
                </section>
            </aside>
        </div>
    </div>
</template>
