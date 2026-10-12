<script setup lang="ts">
import { Plus, Clock } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useList } from '@/composables/useList';
import { formatDateTime, relativeTime } from '@/utils/format';

const list = useList<any>('/reservations', { q: '', status: 'active' });
</script>

<template>
    <div>
        <PageHeader title="Reservations" subtitle="Tyres held for customers. Reserved tyres stay in stock but cannot be sold to anyone else.">
            <template #actions><RouterLink to="/reservations/new" class="btn btn-primary"><Plus class="size-4" /> Create reservation</RouterLink></template>
        </PageHeader>
        <div class="mb-4 grid grid-cols-2 gap-3 sm:max-w-xl">
            <div><label class="field-label" for="res-q">Search</label><input id="res-q" v-model="list.filters.q" type="search" class="field" placeholder="Name, phone or number" /></div>
            <div><label class="field-label" for="res-s">Status</label><select id="res-s" v-model="list.filters.status" class="field"><option value="active">Active</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option><option value="expired">Expired</option><option value="all">All</option></select></div>
        </div>
        <section class="panel overflow-hidden">
            <AsyncState :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" empty-title="No reservations" @retry="list.load()">
                <ul class="divide-y divide-line-soft">
                    <li v-for="r in list.items.value" :key="r.id">
                        <RouterLink :to="`/reservations/${r.id}`" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-surface sm:px-5">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">{{ r.customer_name }} <span class="font-normal text-muted">{{ r.customer_phone }}</span></p>
                                <p class="text-sm text-muted">{{ r.reservation_no }} · {{ r.items.map((i: any) => `${i.quantity} × ${i.product}`).join(', ') }}</p>
                            </div>
                            <div class="text-right text-sm">
                                <StatusBadge :status="r.status" />
                                <p v-if="r.status === 'active'" class="mt-1 flex items-center gap-1" :class="r.is_expiring_soon ? 'font-semibold text-warn' : 'text-muted'"><Clock class="size-3.5" /> expires {{ relativeTime(r.expires_at) }} ({{ formatDateTime(r.expires_at) }})</p>
                            </div>
                        </RouterLink>
                    </li>
                </ul>
                <PaginationBar :page="list.page.value" :last-page="list.lastPage.value" :total="list.total.value" @change="list.load" />
            </AsyncState>
        </section>
    </div>
</template>
