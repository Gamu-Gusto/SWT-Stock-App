<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Trash2, CalendarClock } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import FormField from '@/components/ui/FormField.vue';
import ProductPicker from '@/components/inventory/ProductPicker.vue';
import SizeChip from '@/components/ui/SizeChip.vue';
import { get, post } from '@/api/http';
import { useLookups } from '@/stores/lookups';
import { useSubmit } from '@/composables/useForm';
import type { Product, TyreUnit } from '@/types';

interface Line { key: string; product_id: number; tyre_unit_id?: number; unit_code?: string; name: string; size?: string; available: number; quantity: number }

const route = useRoute();
const router = useRouter();
const lookups = useLookups();
const submit = useSubmit();
const lines = ref<Line[]>([]);
const form = reactive({ customer_name: '', customer_phone: '', notes: '', expires_hours: lookups.settings?.reservation_hours ?? 24 });

function add(p: Product, u?: TyreUnit) {
    const key = u ? `u${u.id}` : `p${p.id}`;
    if (lines.value.some((l) => l.key === key)) return;
    lines.value.push({ key, product_id: p.id, tyre_unit_id: u?.id, unit_code: u?.unit_code, name: `${p.brand?.name ?? ''} ${p.model}`, size: p.spec?.size, available: u ? 1 : p.qty_available, quantity: 1 });
}
onMounted(async () => {
    if (route.query.product_id) {
        const p = (await get<{ data: Product }>(`/products/${route.query.product_id}`)).data;
        if (p.tracking === 'quantity') add(p);
    }
});

const invalid = computed(() => !lines.value.length || lines.value.some((l) => l.quantity < 1 || l.quantity > l.available));

async function save() {
    const res = await submit.run((key) => post('/reservations', {
        ...form,
        idempotency_key: key,
        items: lines.value.map((l) => (l.tyre_unit_id ? { tyre_unit_id: l.tyre_unit_id } : { product_id: l.product_id, quantity: l.quantity })),
    }), 'Tyres reserved.');
    if (res) router.replace(`/reservations/${res.data.id}`);
}
</script>

<template>
    <div class="max-w-3xl">
        <PageHeader title="Create reservation" back="/reservations" :subtitle="`Hold tyres for a customer. The reservation expires automatically after the period you choose (default ${lookups.settings?.reservation_hours ?? 24} hours).`" />
        <form class="space-y-5" @submit.prevent="save">
            <section class="panel p-5">
                <h2 class="panel-title mb-3">Tyres</h2>
                <ProductPicker in-stock-only @pick="add" />
                <ul v-if="lines.length" class="mt-3 divide-y divide-line-soft rounded-[10px] border border-line">
                    <li v-for="(l, i) in lines" :key="l.key" class="flex flex-wrap items-center gap-3 p-3">
                        <SizeChip :size="l.size" /><span class="flex-1 font-semibold">{{ l.name }} <span v-if="l.unit_code" class="text-muted">{{ l.unit_code }}</span></span>
                        <label v-if="!l.tyre_unit_id" class="flex items-center gap-2 text-sm">Qty <input v-model.number="l.quantity" type="number" min="1" :max="l.available" class="field !w-20" /></label>
                        <span class="text-sm text-muted">{{ l.available }} available</span>
                        <button type="button" class="btn btn-ghost btn-sm text-bad" aria-label="Remove" @click="lines.splice(i, 1)"><Trash2 class="size-4" /></button>
                        <p v-if="l.quantity > l.available" class="field-error w-full">Only {{ l.available }} available.</p>
                    </li>
                </ul>
            </section>
            <section class="panel grid gap-4 p-5 sm:grid-cols-2">
                <FormField v-slot="{ id }" label="Customer name" required :error="submit.fieldError('customer_name')"><input :id="id" v-model="form.customer_name" class="field" required /></FormField>
                <FormField v-slot="{ id }" label="Customer phone" required :error="submit.fieldError('customer_phone')"><input :id="id" v-model="form.customer_phone" type="tel" class="field" required /></FormField>
                <FormField v-slot="{ id }" label="Hold for (hours)" :error="submit.fieldError('expires_hours')"><input :id="id" v-model.number="form.expires_hours" type="number" min="1" max="720" class="field" /></FormField>
                <FormField v-slot="{ id }" label="Notes"><input :id="id" v-model="form.notes" class="field" placeholder="e.g. fitting booked Saturday 9:00" /></FormField>
            </section>
            <button type="submit" class="btn btn-primary" :disabled="invalid || submit.busy.value"><CalendarClock class="size-5" /> Reserve tyres</button>
        </form>
    </div>
</template>
