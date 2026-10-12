<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Play, ClipboardList, SearchCheck, Lock, XCircle, Archive } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { get, post, type ApiError } from '@/api/http';
import { useSubmit } from '@/composables/useForm';
import { formatDateTime } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const st = ref<any>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const submit = useSubmit();

async function load() {
    loading.value = true;
    try {
        st.value = (await get(`/stocktakes/${route.params.id}`)).data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function act(path: string, msg: string, body: object = {}) {
    if (await submit.run(() => post(`/stocktakes/${route.params.id}/${path}`, body), msg)) load();
}
async function cancel() {
    const reason = prompt('Why is this stocktake being cancelled?');
    if (reason) act('cancel', 'Stocktake cancelled.', { reason });
}
async function review() {
    if (await submit.run(() => post(`/stocktakes/${route.params.id}/review`), 'Review started.')) router.push(`/stocktakes/${route.params.id}/review`);
}
</script>

<template>
    <AsyncState :loading="loading" :error="error" @retry="load">
        <div v-if="st" class="max-w-4xl">
            <PageHeader :title="st.title" :subtitle="`${st.stocktake_no} · ${st.scope_label}`" back="/stocktakes">
                <template #meta><div class="mt-2 flex items-center gap-2"><StatusBadge :status="st.status" /><span class="text-sm text-muted">{{ st.is_blind ? 'Blind count' : 'Expected quantities visible to counters' }}</span></div></template>
                <template #actions>
                    <button v-if="st.status === 'draft' && st.can_manage" type="button" class="btn btn-primary" :disabled="submit.busy.value" @click="act('start', 'Stocktake started.')"><Play class="size-4" /> Start counting</button>
                    <RouterLink v-if="st.status === 'in_progress' && st.can_count" :to="`/stocktakes/${st.id}/count`" class="btn btn-primary"><ClipboardList class="size-4" /> Count stock</RouterLink>
                    <button v-if="st.status === 'submitted' && st.can_approve" type="button" class="btn btn-primary" @click="review"><SearchCheck class="size-4" /> Review differences</button>
                    <RouterLink v-if="['under_review', 'approved', 'closed'].includes(st.status) && st.can_approve" :to="`/stocktakes/${st.id}/review`" class="btn btn-primary"><SearchCheck class="size-4" /> {{ st.status === 'under_review' ? 'Continue review' : 'Discrepancy report' }}</RouterLink>
                    <button v-if="st.status === 'approved' && st.can_approve" type="button" class="btn btn-secondary" @click="act('close', 'Stocktake closed.')"><Archive class="size-4" /> Close</button>
                    <button v-if="['draft', 'in_progress', 'submitted', 'under_review'].includes(st.status) && st.can_manage" type="button" class="btn btn-ghost text-bad" @click="cancel"><XCircle class="size-4" /> Cancel</button>
                </template>
            </PageHeader>

            <div v-if="st.status === 'in_progress'" class="mb-5 flex gap-3 rounded-[10px] border border-info/30 bg-info-soft p-4 text-sm text-info">
                <Lock class="size-5 shrink-0" />
                <p v-if="st.lock_mode === 'block'"><strong>Counting lock is on.</strong> Sales, receipts, transfers and adjustments of the tyres in this count are paused at the counted locations until the count is submitted. Reservations still work.</p>
                <p v-else><strong>Tracking mode.</strong> Sales and receipts continue during the count. Each count records the system quantity at that moment, and the review shows every movement made during the count.</p>
            </div>

            <div class="mb-5 grid grid-cols-3 overflow-hidden rounded-[10px] border border-line bg-panel">
                <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Lines</p><p class="qty !text-3xl">{{ st.items_count }}</p></div>
                <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Counted</p><p class="qty !text-3xl">{{ st.counted_count }}</p></div>
                <div class="p-4"><p class="text-sm text-muted">Differences</p><p class="qty !text-3xl">{{ st.can_view_expected ? st.variance_count : '–' }}</p></div>
            </div>

            <section class="panel">
                <dl class="grid gap-4 p-5 text-sm sm:grid-cols-2">
                    <div><dt class="text-muted">Counters</dt><dd class="font-medium">{{ st.assignees.map((a: any) => a.name).join(', ') || 'None assigned' }}</dd></div>
                    <div><dt class="text-muted">Created</dt><dd>{{ formatDateTime(st.created_at) }} by {{ st.created_by }}</dd></div>
                    <div><dt class="text-muted">Started</dt><dd>{{ formatDateTime(st.started_at) }}</dd></div>
                    <div><dt class="text-muted">Submitted</dt><dd>{{ st.submitted_at ? `${formatDateTime(st.submitted_at)} by ${st.submitted_by}` : '–' }}</dd></div>
                    <div><dt class="text-muted">Approved</dt><dd>{{ st.approved_at ? `${formatDateTime(st.approved_at)} by ${st.approved_by}` : '–' }}</dd></div>
                    <div><dt class="text-muted">Adjustment reference</dt><dd>{{ st.adjustment_reference ?? '–' }}</dd></div>
                    <div v-if="st.notes" class="sm:col-span-2"><dt class="text-muted">Notes</dt><dd>{{ st.notes }}</dd></div>
                    <div v-if="st.approval_notes" class="sm:col-span-2"><dt class="text-muted">Approval notes</dt><dd>{{ st.approval_notes }}</dd></div>
                </dl>
            </section>
        </div>
    </AsyncState>
</template>
