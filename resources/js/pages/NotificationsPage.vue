<script setup lang="ts">
import { TriangleAlert, Ban, ClipboardCheck, CalendarClock, Bell, CheckCheck } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import { post } from '@/api/http';
import { useList } from '@/composables/useList';
import { useUi } from '@/stores/ui';
import { formatDateTime } from '@/utils/format';

const ui = useUi();
const list = useList<any>('/notifications', { state: 'open', type: '' });
const icons: Record<string, any> = { low_stock: TriangleAlert, out_of_stock: Ban, pending_stocktake: ClipboardCheck, pending_approval: CheckCheck, reservation_expiring: CalendarClock };
const tones: Record<string, string> = { danger: 'text-bad', warning: 'text-warn', info: 'text-info' };

async function read(n: any) {
    if (!n.is_read) {
        await post(`/notifications/${n.id}/read`);
        n.is_read = true;
        ui.refreshUnread();
    }
}
async function readAll() {
    await post('/notifications/read-all');
    list.load();
    ui.refreshUnread();
}
</script>

<template>
    <div class="max-w-4xl">
        <PageHeader title="Notifications" subtitle="Stock alerts, counts and approvals that need attention. Alerts clear themselves when the situation is resolved.">
            <template #actions><button type="button" class="btn btn-secondary" @click="readAll"><CheckCheck class="size-4" /> Mark all read</button></template>
        </PageHeader>
        <div class="mb-4 flex flex-wrap gap-2">
            <select v-model="list.filters.type" class="field !w-auto" aria-label="Type"><option value="">All types</option><option value="low_stock">Low stock</option><option value="out_of_stock">Out of stock</option><option value="pending_stocktake">Stocktakes</option><option value="pending_approval">Approvals</option><option value="reservation_expiring">Reservations</option></select>
            <select v-model="list.filters.state" class="field !w-auto" aria-label="State"><option value="open">Needs attention</option><option value="all">Including resolved</option></select>
        </div>
        <section class="panel overflow-hidden">
            <AsyncState :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" empty-title="All clear" empty-text="Nothing needs attention right now." @retry="list.load()">
                <ul class="divide-y divide-line-soft">
                    <li v-for="n in list.items.value" :key="n.id" :class="{ 'bg-info-soft/40': !n.is_read && !n.resolved_at }">
                        <RouterLink :to="n.link ?? '/notifications'" class="flex gap-3 px-5 py-3 hover:bg-surface" @click="read(n)">
                            <component :is="icons[n.type] ?? Bell" class="mt-0.5 size-5 shrink-0" :class="tones[n.severity]" />
                            <div class="min-w-0 flex-1">
                                <p :class="n.is_read ? 'font-medium' : 'font-bold'">{{ n.title }}</p>
                                <p class="text-sm text-muted">{{ n.message }}</p>
                                <p class="text-[12px] text-faint">{{ formatDateTime(n.updated_at) }}<template v-if="n.resolved_at"> · resolved {{ formatDateTime(n.resolved_at) }}</template></p>
                            </div>
                        </RouterLink>
                    </li>
                </ul>
                <PaginationBar :page="list.page.value" :last-page="list.lastPage.value" :total="list.total.value" @change="list.load" />
            </AsyncState>
        </section>
    </div>
</template>
