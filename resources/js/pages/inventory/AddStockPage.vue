<script setup lang="ts">
import { computed } from 'vue';
import { Truck, CirclePlus, Recycle, Upload } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const options = computed(() =>
    [
        { to: '/receiving/new', icon: Truck, title: 'Receive a delivery', text: 'Tyres arrived from a supplier. Capture the delivery note or invoice and the quantities.', can: 'receiving.create' },
        { to: '/inventory/new', icon: CirclePlus, title: 'Add a new tyre record', text: 'A size, brand or model the shop has not stocked before. You can enter tyres already on hand as an opening balance.', can: 'inventory.manage' },
        { to: '/inventory/new?condition=used', icon: Recycle, title: 'Register used tyres', text: 'Create the used tyre record, then add each tyre with its tread depth, grade, DOT code and price.', can: 'inventory.manage' },
        { to: '/import', icon: Upload, title: 'Import from a spreadsheet', text: 'Move existing stock lists from Excel or CSV, with a preview before anything is saved.', can: 'inventory.import' },
    ].filter((o) => auth.can(o.can)),
);
</script>

<template>
    <div class="max-w-4xl">
        <PageHeader title="Add stock" subtitle="Choose what you are adding. Every option records a traceable stock movement." />
        <div class="grid gap-3 sm:grid-cols-2">
            <RouterLink v-for="o in options" :key="o.to" :to="o.to" class="flex gap-4 rounded-[10px] border border-line bg-panel p-5 hover:border-asphalt">
                <component :is="o.icon" class="mt-0.5 size-7 shrink-0 text-brand-text" />
                <span>
                    <span class="block font-display text-xl font-semibold">{{ o.title }}</span>
                    <span class="mt-1 block text-sm text-muted">{{ o.text }}</span>
                </span>
            </RouterLink>
        </div>
    </div>
</template>
