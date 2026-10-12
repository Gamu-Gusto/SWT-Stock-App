<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import TabLinks from '@/components/ui/TabLinks.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import { useList } from '@/composables/useList';
import { useLookups } from '@/stores/lookups';
import { useAuth } from '@/stores/auth';
import { formatDateTime, formatMoney } from '@/utils/format';

const lookups = useLookups();
const auth = useAuth();
const list = auth.can('movements.view') ? useList<any>('/movements', { type: '', location_id: '', user_id: '', direction: '', reference: '', date_from: '', date_to: '' }, { debounceKeys: ['reference'] }) : null;
const tabs = [
    { label: 'Movement history', to: '/movements', can: ['movements.view'] },
    { label: 'Transfers', to: '/movements/transfers', can: ['transfers.create', 'movements.view'] },
    { label: 'Adjustments', to: '/movements/adjustments', can: ['adjustments.request', 'adjustments.approve'] },
];
</script>

<template>
    <div>
        <PageHeader title="Stock movements" subtitle="Every change to physical stock, permanently recorded. Entries cannot be edited or deleted – corrections are new movements." />
        <TabLinks :tabs="tabs" />
        <template v-if="list">
            <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                <div><label class="field-label" for="m-t">Type</label><select id="m-t" v-model="list.filters.type" class="field"><option value="">All types</option><option v-for="(l, k) in lookups.movementTypes" :key="k" :value="k">{{ l }}</option></select></div>
                <div><label class="field-label" for="m-d">Direction</label><select id="m-d" v-model="list.filters.direction" class="field"><option value="">In and out</option><option value="in">Stock in</option><option value="out">Stock out</option></select></div>
                <div><label class="field-label" for="m-l">Location</label><select id="m-l" v-model="list.filters.location_id" class="field"><option value="">All</option><option v-for="l in lookups.locations" :key="l.id" :value="l.id">{{ l.name }}</option></select></div>
                <div v-if="lookups.employees.length"><label class="field-label" for="m-u">Employee</label><select id="m-u" v-model="list.filters.user_id" class="field"><option value="">Everyone</option><option v-for="e in lookups.employees" :key="e.id" :value="e.id">{{ e.name }}</option></select></div>
                <div><label class="field-label" for="m-r">Reference</label><input id="m-r" v-model="list.filters.reference" class="field" placeholder="SL-, GRN-, ST-" /></div>
                <div><label class="field-label" for="m-f">From</label><input id="m-f" v-model="list.filters.date_from" type="date" class="field" /></div>
                <div><label class="field-label" for="m-to">To</label><input id="m-to" v-model="list.filters.date_to" type="date" class="field" /></div>
            </div>
            <section class="panel overflow-hidden">
                <AsyncState :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" empty-title="No movements match" @retry="list.load()">
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Movement</th><th>Date</th><th>Type</th><th>Tyre</th><th>Location</th><th class="num">Before</th><th class="num">Change</th><th class="num">After</th><th>Reference / reason</th><th>Employee</th><th v-if="auth.can('financials.view')" class="num">Cost</th></tr></thead>
                            <tbody>
                                <tr v-for="m in list.items.value" :key="m.id">
                                    <td class="text-[13px] text-muted">{{ m.movement_no }}</td>
                                    <td class="whitespace-nowrap">{{ formatDateTime(m.created_at) }}</td>
                                    <td class="font-medium">{{ m.type_label }}</td>
                                    <td><RouterLink :to="`/inventory/${m.product?.id}`" class="hover:underline">{{ m.product?.display_name }}</RouterLink><div class="text-[12px] text-muted">{{ m.product?.condition === 'used' ? 'Used' : 'New' }}<template v-if="m.unit_code"> · {{ m.unit_code }}</template></div></td>
                                    <td>{{ m.location }}<div v-if="m.from_location && m.to_location && m.type === 'stock_transfer'" class="text-[12px] text-muted">{{ m.from_location }} → {{ m.to_location }}</div></td>
                                    <td class="num">{{ m.previous_qty }}</td>
                                    <td class="num font-semibold" :class="m.quantity_change > 0 ? 'text-ok' : 'text-bad'">{{ m.quantity_change > 0 ? '+' : '' }}{{ m.quantity_change }}</td>
                                    <td class="num">{{ m.resulting_qty }}</td>
                                    <td class="max-w-[240px] text-[13px]">{{ m.reference_no }}<div class="truncate text-muted" :title="m.reason">{{ m.reason }}</div></td>
                                    <td class="text-[13px]">{{ m.user }}<div v-if="m.approved_by" class="text-muted">approved: {{ m.approved_by }}</div></td>
                                    <td v-if="auth.can('financials.view')" class="num">{{ m.total_cost ? formatMoney(m.total_cost) : '–' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <PaginationBar :page="list.page.value" :last-page="list.lastPage.value" :total="list.total.value" @change="list.load" />
                </AsyncState>
            </section>
        </template>
        <p v-else class="text-muted">Use the Adjustments tab to request stock corrections.</p>
    </div>
</template>
