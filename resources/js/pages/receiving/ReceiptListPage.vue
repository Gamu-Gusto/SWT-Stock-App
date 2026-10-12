<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useList } from '@/composables/useList';
import { useLookups } from '@/stores/lookups';
import { useAuth } from '@/stores/auth';
import { formatDate, formatMoney } from '@/utils/format';

const lookups = useLookups();
const auth = useAuth();
const list = useList<any>('/receipts', { q: '', status: '', supplier_id: '', date_from: '', date_to: '' });
</script>

<template>
    <div>
        <PageHeader title="Stock receiving" subtitle="Deliveries from suppliers. Drafts do not change stock until they are posted.">
            <template #actions><RouterLink v-if="auth.can('receiving.create')" to="/receiving/new" class="btn btn-primary"><Plus class="size-4" /> Receive stock</RouterLink></template>
        </PageHeader>
        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
            <div class="col-span-2 md:col-span-1"><label class="field-label" for="r-q">Search</label><input id="r-q" v-model="list.filters.q" type="search" class="field" placeholder="GRN or invoice no." /></div>
            <div><label class="field-label" for="r-s">Status</label><select id="r-s" v-model="list.filters.status" class="field"><option value="">All</option><option value="draft">Draft</option><option value="posted">Posted</option><option value="reversed">Reversed</option></select></div>
            <div><label class="field-label" for="r-sup">Supplier</label><select id="r-sup" v-model="list.filters.supplier_id" class="field"><option value="">All</option><option v-for="s in lookups.suppliers" :key="s.id" :value="s.id">{{ s.company_name }}</option></select></div>
            <div><label class="field-label" for="r-from">From</label><input id="r-from" v-model="list.filters.date_from" type="date" class="field" /></div>
            <div><label class="field-label" for="r-to">To</label><input id="r-to" v-model="list.filters.date_to" type="date" class="field" /></div>
        </div>
        <section class="panel overflow-hidden">
            <AsyncState :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" empty-title="No deliveries recorded" empty-text="Receive a delivery to add stock from a supplier." @retry="list.load()">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Receipt</th><th>Delivery date</th><th>Supplier</th><th>Invoice / delivery note</th><th class="num">Tyres</th><th class="num">Cost</th><th>Status</th><th>Captured by</th></tr></thead>
                        <tbody>
                            <tr v-for="r in list.items.value" :key="r.id">
                                <td><RouterLink :to="`/receiving/${r.id}`" class="font-semibold hover:underline">{{ r.receipt_no }}</RouterLink></td>
                                <td>{{ formatDate(r.delivery_date) }}</td>
                                <td>{{ r.supplier?.name }}</td>
                                <td>{{ r.supplier_reference ?? '–' }}</td>
                                <td class="num">{{ r.total_quantity }}</td>
                                <td class="num">{{ r.total_cost !== null ? formatMoney(r.total_cost) : '–' }}</td>
                                <td><StatusBadge :status="r.status" /></td>
                                <td class="text-[13px] text-muted">{{ r.created_by }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <PaginationBar :page="list.page.value" :last-page="list.lastPage.value" :total="list.total.value" @change="list.load" />
            </AsyncState>
        </section>
    </div>
</template>
