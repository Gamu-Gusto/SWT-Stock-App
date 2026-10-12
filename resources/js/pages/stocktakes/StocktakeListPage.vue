<script setup lang="ts">
import { Plus } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import TabLinks from '@/components/ui/TabLinks.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useList } from '@/composables/useList';
import { useAuth } from '@/stores/auth';
import { formatDate } from '@/utils/format';

const props = defineProps<{ approvals?: boolean }>();
const auth = useAuth();
const list = useList<any>('/stocktakes', { status: props.approvals ? 'submitted,under_review' : 'all' });
const tabs = [
    { label: 'All stocktakes', to: '/stocktakes' },
    { label: 'Waiting for approval', to: '/stocktakes/approvals', can: ['stocktake.approve'] },
];
</script>

<template>
    <div>
        <PageHeader :title="approvals ? 'Stocktake approvals' : 'Stocktaking'" :subtitle="approvals ? 'Submitted counts with differences to review.' : auth.can('stocktake.manage') ? 'Physical counts compared with the system. Stock only changes after a manager approves the differences.' : 'Counts assigned to you.'">
            <template #actions><RouterLink v-if="auth.can('stocktake.manage')" to="/stocktakes/new" class="btn btn-primary"><Plus class="size-4" /> New stocktake</RouterLink></template>
        </PageHeader>
        <TabLinks v-if="auth.can('stocktake.approve')" :tabs="tabs" />
        <section class="panel overflow-hidden">
            <AsyncState :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" :empty-title="approvals ? 'Nothing waiting for approval' : 'No stocktakes yet'" :empty-text="auth.can('stocktake.manage') ? 'Start a stocktake for a rack, brand, size or the whole store.' : 'When a manager assigns you a count it appears here.'" @retry="list.load()">
                <ul class="divide-y divide-line-soft">
                    <li v-for="s in list.items.value" :key="s.id">
                        <RouterLink :to="s.status === 'in_progress' && !auth.can('stocktake.manage') ? `/stocktakes/${s.id}/count` : `/stocktakes/${s.id}`" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 hover:bg-surface sm:px-5">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">{{ s.title }} <span class="font-normal text-muted">{{ s.stocktake_no }}</span></p>
                                <p class="text-sm text-muted">{{ s.scope_label }} · {{ s.is_blind ? 'Blind count' : 'Expected quantities shown' }} · counters: {{ s.assignees.map((a: any) => a.name).join(', ') || 'none' }}</p>
                            </div>
                            <div class="text-right text-sm">
                                <StatusBadge :status="s.status" />
                                <p class="mt-1 text-muted">{{ s.counted_count }}/{{ s.items_count }} counted<template v-if="auth.can('stocktake.approve') && s.variance_count"> · {{ s.variance_count }} differences</template></p>
                                <p class="text-muted">{{ formatDate(s.started_at ?? s.created_at) }}</p>
                            </div>
                        </RouterLink>
                    </li>
                </ul>
                <PaginationBar :page="list.page.value" :last-page="list.lastPage.value" :total="list.total.value" @change="list.load" />
            </AsyncState>
        </section>
    </div>
</template>
