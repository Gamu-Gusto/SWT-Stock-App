<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useList } from '@/composables/useList';
import { formatDate, formatMoney } from '@/utils/format';

const list = useList<any>('/quotations', { q: '', status: 'all' });
</script>

<template>
    <div>
        <PageHeader title="Quotations" subtitle="Price quotes for customers. Quotations never change stock.">
            <template #actions><RouterLink to="/quotations/new" class="btn btn-primary"><Plus class="size-4" /> New quotation</RouterLink></template>
        </PageHeader>
        <div class="mb-4 grid grid-cols-2 gap-3 sm:max-w-xl">
            <div><label class="field-label" for="q-q">Search</label><input id="q-q" v-model="list.filters.q" type="search" class="field" placeholder="Name, phone or number" /></div>
            <div><label class="field-label" for="q-s">Status</label><select id="q-s" v-model="list.filters.status" class="field"><option value="all">All</option><option value="open">Open</option><option value="converted">Converted</option><option value="expired">Expired</option><option value="cancelled">Cancelled</option></select></div>
        </div>
        <section class="panel overflow-hidden">
            <AsyncState :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" empty-title="No quotations" @retry="list.load()">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Quotation</th><th>Customer</th><th>Date</th><th>Valid until</th><th class="num">Total</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr v-for="q in list.items.value" :key="q.id">
                                <td><RouterLink :to="`/quotations/${q.id}`" class="font-semibold hover:underline">{{ q.quotation_no }}</RouterLink></td>
                                <td>{{ q.customer_name ?? '–' }}<div class="text-[12.5px] text-muted">{{ q.customer_phone }}</div></td>
                                <td>{{ formatDate(q.created_at) }}</td>
                                <td>{{ formatDate(q.valid_until) }}</td>
                                <td class="num font-semibold">{{ formatMoney(q.total) }}</td>
                                <td><StatusBadge :status="q.status" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <PaginationBar :page="list.page.value" :last-page="list.lastPage.value" :total="list.total.value" @change="list.load" />
            </AsyncState>
        </section>
    </div>
</template>
