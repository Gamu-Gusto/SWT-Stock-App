<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import TabLinks from '@/components/ui/TabLinks.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useList } from '@/composables/useList';
import { useAuth } from '@/stores/auth';
import { useLookups } from '@/stores/lookups';
import { formatDateTime, formatMoney } from '@/utils/format';

const auth = useAuth();
const lookups = useLookups();
const list = useList<any>('/sales', { q: '', status: '', payment_method: '', user_id: '', date_from: '', date_to: '' });
const tabs = [
    { label: 'Sales', to: '/sales' },
    { label: 'Returns to inspect', to: '/sales/returns', can: ['sales.returns'] },
];
</script>

<template>
    <div>
        <PageHeader title="Sales" :subtitle="auth.can('sales.view_all') ? 'All recorded sales.' : 'Your recorded sales.'">
            <template #actions><RouterLink v-if="auth.can('sales.create')" to="/sales/new" class="btn btn-primary"><Plus class="size-4" /> New sale</RouterLink></template>
        </PageHeader>
        <TabLinks :tabs="tabs" />
        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-6">
            <div class="col-span-2"><label class="field-label" for="s-q">Search</label><input id="s-q" v-model="list.filters.q" type="search" class="field" placeholder="Sale no., customer or phone" /></div>
            <div><label class="field-label" for="s-st">Status</label><select id="s-st" v-model="list.filters.status" class="field"><option value="">All</option><option value="completed">Completed</option><option value="draft">Draft</option><option value="voided">Voided</option><option value="partially_returned">Part returned</option><option value="returned">Returned</option></select></div>
            <div><label class="field-label" for="s-pm">Payment</label><select id="s-pm" v-model="list.filters.payment_method" class="field"><option value="">All</option><option value="cash">Cash</option><option value="card">Card</option><option value="eft">EFT</option></select></div>
            <div><label class="field-label" for="s-from">From</label><input id="s-from" v-model="list.filters.date_from" type="date" class="field" /></div>
            <div><label class="field-label" for="s-to">To</label><input id="s-to" v-model="list.filters.date_to" type="date" class="field" /></div>
            <div v-if="auth.can('sales.view_all') && lookups.employees.length"><label class="field-label" for="s-u">Employee</label><select id="s-u" v-model="list.filters.user_id" class="field"><option value="">Everyone</option><option v-for="e in lookups.employees" :key="e.id" :value="e.id">{{ e.name }}</option></select></div>
        </div>
        <section class="panel overflow-hidden">
            <AsyncState :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" empty-title="No sales found" @retry="list.load()">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Sale</th><th>Date</th><th>Customer</th><th>Employee</th><th>Payment</th><th class="num">Total</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr v-for="s in list.items.value" :key="s.id">
                                <td><RouterLink :to="`/sales/${s.id}`" class="font-semibold hover:underline">{{ s.sale_no }}</RouterLink></td>
                                <td class="whitespace-nowrap">{{ formatDateTime(s.completed_at ?? s.created_at) }}</td>
                                <td>{{ s.customer_name ?? '–' }}<div v-if="s.customer_phone" class="text-[12.5px] text-muted">{{ s.customer_phone }}</div></td>
                                <td>{{ s.user }}</td>
                                <td class="uppercase">{{ s.payment_method ?? '–' }}</td>
                                <td class="num font-semibold">{{ formatMoney(s.total) }}</td>
                                <td><StatusBadge :status="s.status" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <PaginationBar :page="list.page.value" :last-page="list.lastPage.value" :total="list.total.value" @change="list.load" />
            </AsyncState>
        </section>
    </div>
</template>
