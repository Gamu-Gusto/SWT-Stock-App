<script setup lang="ts">
import { computed } from 'vue';
import { Trash2, Plus, Minus } from 'lucide-vue-next';
import ProductPicker from '@/components/inventory/ProductPicker.vue';
import SizeChip from '@/components/ui/SizeChip.vue';
import { useLookups } from '@/stores/lookups';
import { formatMoney } from '@/utils/format';
import type { Product, TyreUnit } from '@/types';
import type { CartLine, ServiceLine } from '@/types/cart';
import { cents, fromCents } from '@/utils/money';

/** Shared editor for sale and quotation lines: tyres (new by quantity, used per unit) plus services. */
const props = defineProps<{ lines: CartLine[]; services: ServiceLine[]; errorFor?: (k: string) => string | undefined; checkStock?: boolean; lockedLines?: boolean }>();
const lookups = useLookups();

function lineFromProduct(p: Product, u?: TyreUnit): CartLine {
    return {
        key: u ? `u${u.id}` : `p${p.id}`,
        product_id: p.id,
        tyre_unit_id: u?.id,
        unit_code: u?.unit_code,
        name: `${p.brand?.name ?? ''} ${p.model}`.trim(),
        size: p.spec?.size,
        condition: p.condition,
        available: u ? 1 : p.qty_available,
        quantity: 1,
        unit_price: u ? u.selling_price : p.effective_price,
        list_price: u ? u.selling_price : p.effective_price,
        min_price: u ? null : p.min_selling_price,
        discount: '',
        fees: { fitting: p.fitting_fee, balancing: p.balancing_fee, alignment: p.alignment_fee },
    };
}

function add(p: Product, u?: TyreUnit) {
    const line = lineFromProduct(p, u);
    const existing = props.lines.find((l) => l.key === line.key);
    if (existing) {
        if (!u) existing.quantity++;
        return;
    }
    props.lines.push(line);
}
defineExpose({ add, lineFromProduct });

function addService(code: string) {
    const qty = props.lines.reduce((s, l) => s + l.quantity, 0) || 1;
    const fee = props.lines.map((l) => l.fees[code as 'fitting' | 'balancing' | 'alignment']).find((f) => f) ?? '';
    props.services.push({
        key: `${code}-${Date.now()}`,
        service_code: code,
        description: lookups.serviceCodes[code] ?? 'Service',
        quantity: code === 'alignment' ? 1 : qty,
        unit_price: fee ?? '',
        discount: '',
    });
}

const belowMin = (l: CartLine) => l.min_price !== null && l.min_price !== '' && Number(l.unit_price) < Number(l.min_price);
const quickServices = computed(() => Object.entries(lookups.serviceCodes));
</script>

<template>
    <div>
        <ProductPicker v-if="!lockedLines" :in-stock-only="checkStock" placeholder="Add tyres – type the size, e.g. 205/55R16" @pick="add" />

        <ul class="mt-3 divide-y divide-line-soft rounded-[10px] border border-line" :class="{ hidden: !lines.length }">
            <li v-for="(l, i) in lines" :key="l.key" class="p-3 sm:p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <SizeChip :size="l.size" />
                    <span class="font-semibold">{{ l.name }}</span>
                    <span class="text-sm" :class="l.condition === 'used' ? 'text-warn' : 'text-muted'">{{ l.condition === 'used' ? `Used ${l.unit_code ?? ''}` : 'New' }}</span>
                    <span v-if="l.reservation_item_id" class="rounded-full bg-info-soft px-2 text-xs font-semibold text-info">reserved</span>
                    <button v-if="!lockedLines" type="button" class="btn btn-ghost btn-sm ml-auto text-bad" aria-label="Remove line" @click="lines.splice(i, 1)"><Trash2 class="size-4" /></button>
                </div>
                <div class="mt-2 grid grid-cols-2 items-end gap-2 sm:grid-cols-4">
                    <div>
                        <span class="field-label">Quantity</span>
                        <div v-if="!l.tyre_unit_id" class="flex items-center">
                            <button type="button" class="btn btn-secondary !rounded-r-none !px-3" aria-label="One less" :disabled="l.quantity <= 1 || lockedLines" @click="l.quantity--"><Minus class="size-4" /></button>
                            <input v-model.number="l.quantity" type="number" min="1" class="field !rounded-none text-center font-display !text-xl font-semibold" :aria-label="`Quantity of ${l.name}`" :disabled="lockedLines" />
                            <button type="button" class="btn btn-secondary !rounded-l-none !px-3" aria-label="One more" :disabled="lockedLines" @click="l.quantity++"><Plus class="size-4" /></button>
                        </div>
                        <p v-else class="py-2 font-semibold">1 tyre</p>
                    </div>
                    <label>
                        <span class="field-label">Price each</span>
                        <input v-model="l.unit_price" type="number" step="0.01" min="0" inputmode="decimal" class="field" />
                    </label>
                    <label>
                        <span class="field-label">Line discount (R)</span>
                        <input v-model="l.discount" type="number" step="0.01" min="0" inputmode="decimal" class="field" placeholder="0.00" />
                    </label>
                    <p class="pb-2 text-right font-display text-xl font-semibold tabular-nums">{{ formatMoney(fromCents(cents(l.unit_price) * (l.quantity || 0) - cents(l.discount))) }}</p>
                </div>
                <p v-if="checkStock && l.quantity > l.available" class="field-error">Only {{ l.available }} available to sell.</p>
                <p v-if="belowMin(l)" class="mt-1 text-[13px] text-warn">Below the minimum approved price of {{ formatMoney(l.min_price) }} – needs a manager.</p>
                <p v-if="errorFor?.(`items.${i}.quantity`)" class="field-error">{{ errorFor(`items.${i}.quantity`) }}</p>
            </li>
        </ul>

        <div class="mt-4">
            <p class="field-label">Services</p>
            <div class="flex flex-wrap gap-2">
                <button v-for="[code, name] in quickServices" :key="code" type="button" class="btn btn-secondary btn-sm" @click="addService(code)"><Plus class="size-4" /> {{ name }}</button>
            </div>
            <ul v-if="services.length" class="mt-3 divide-y divide-line-soft rounded-[10px] border border-line">
                <li v-for="(s, i) in services" :key="s.key" class="grid grid-cols-2 items-end gap-2 p-3 sm:grid-cols-[2fr_1fr_1fr_1fr_auto]">
                    <label class="col-span-2 sm:col-span-1"><span class="field-label">Service</span><input v-model="s.description" class="field" /></label>
                    <label><span class="field-label">Qty</span><input v-model.number="s.quantity" type="number" min="1" class="field" /></label>
                    <label><span class="field-label">Price each</span><input v-model="s.unit_price" type="number" step="0.01" min="0" class="field" required /></label>
                    <label><span class="field-label">Discount</span><input v-model="s.discount" type="number" step="0.01" min="0" class="field" placeholder="0.00" /></label>
                    <button type="button" class="btn btn-ghost text-bad" aria-label="Remove service" @click="services.splice(i, 1)"><Trash2 class="size-4" /></button>
                </li>
            </ul>
        </div>
    </div>
</template>
