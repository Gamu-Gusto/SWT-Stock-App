<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CheckCheck, RotateCcw, Printer, Download } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import SizeChip from '@/components/ui/SizeChip.vue';
import { downloadUrl, get, post, type ApiError } from '@/api/http';
import { useSubmit } from '@/composables/useForm';
import { formatDateTime, formatMoney } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const id = route.params.id;
const st = ref<any>(null);
const items = ref<any[]>([]);
const totals = ref<any>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const onlyDiff = ref(true);
const submit = useSubmit();
const decisions = reactive<Record<number, { approve: boolean; reason: string; recount: boolean }>>({});
const notes = ref('');
const recountReason = ref('');

async function load() {
    loading.value = true;
    try {
        st.value = (await get(`/stocktakes/${id}`)).data;
        const res = await get(`/stocktakes/${id}/items`);
        items.value = res.data;
        totals.value = res.totals;
        for (const i of items.value) decisions[i.id] ??= { approve: true, reason: '', recount: false };
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
const printPage = () => window.print();

const shown = computed(() => (onlyDiff.value ? items.value.filter((i) => i.variance !== 0) : items.value));
const editable = computed(() => ['submitted', 'under_review'].includes(st.value?.status));
const recountIds = computed(() => Object.entries(decisions).filter(([, d]) => d.recount).map(([k]) => Number(k)));

async function requestRecount() {
    if (await submit.run(() => post(`/stocktakes/${id}/recount`, { item_ids: recountIds.value, reason: recountReason.value }), 'Recount requested. Counters have been notified.')) router.push(`/stocktakes/${id}`);
}
async function approve() {
    const diff = items.value.filter((i) => i.variance !== 0);
    const body = { notes: notes.value || null, decisions: diff.map((i) => ({ item_id: i.id, approve: decisions[i.id].approve, reason: decisions[i.id].reason || null })) };
    if (!confirm(`Approve the stocktake? ${diff.filter((i) => decisions[i.id].approve).length} approved difference(s) will be posted to stock.`)) return;
    if (await submit.run(() => post(`/stocktakes/${id}/approve`, body), 'Stocktake approved. Adjustments posted.')) load();
}
</script>

<template>
    <AsyncState :loading="loading" :error="error" @retry="load">
        <div v-if="st">
            <PageHeader :title="`Discrepancy report – ${st.stocktake_no}`" :subtitle="`${st.title} · ${st.scope_label}`" :back="`/stocktakes/${id}`">
                <template #meta><div class="mt-2 flex flex-wrap items-center gap-2"><StatusBadge :status="st.status" /><span class="text-sm text-muted">Counted by {{ st.assignees.map((a: any) => a.name).join(', ') }} · submitted {{ formatDateTime(st.submitted_at) }}</span></div></template>
                <template #actions>
                    <a :href="downloadUrl('/reports/stocktake_variances/export', { format: 'xlsx', stocktake_id: st.id, date_from: '2000-01-01' })" class="btn btn-secondary"><Download class="size-4" /> Excel</a>
                    <button type="button" class="btn btn-secondary" @click="printPage"><Printer class="size-4" /> Print</button>
                </template>
            </PageHeader>

            <div v-if="totals" class="mb-5 grid grid-cols-2 overflow-hidden rounded-[10px] border border-line bg-panel sm:grid-cols-4">
                <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Lines counted</p><p class="qty !text-3xl">{{ totals.counted }}/{{ totals.lines }}</p></div>
                <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Shortage</p><p class="qty !text-3xl text-bad">{{ totals.shortage_qty }}</p></div>
                <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Excess</p><p class="qty !text-3xl text-ok">+{{ totals.excess_qty }}</p></div>
                <div class="p-4"><p class="text-sm text-muted">Cost impact</p><p class="qty !text-3xl">{{ totals.cost_impact !== null ? formatMoney(totals.cost_impact) : '–' }}</p></div>
            </div>

            <label class="no-print mb-3 flex items-center gap-2 text-sm"><input v-model="onlyDiff" type="checkbox" class="size-4" /> Show only lines with a difference</label>
            <section class="panel mb-5 overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Tyre</th><th>Location</th><th class="num">System</th><th class="num">Counted</th><th class="num">Difference</th><th class="num">Moved during count</th><th class="num">Cost impact</th><th>Counted by</th><th v-if="editable" class="no-print">Decision</th><th v-else>Result</th></tr></thead>
                    <tbody>
                        <tr v-for="i in shown" :key="i.id" :class="i.variance < 0 ? 'bg-bad-soft/40' : i.variance > 0 ? 'bg-ok-soft/40' : ''">
                            <td><SizeChip :size="i.size" /> <span class="font-semibold">{{ i.product }}</span><div class="text-[12.5px] text-muted">{{ i.sku }}<template v-if="i.unit_code"> · {{ i.unit_code }}</template><template v-if="i.is_unexpected"> · not expected here</template></div></td>
                            <td>{{ i.location }}</td>
                            <td class="num">{{ i.system_qty_at_count ?? i.snapshot_qty }}</td>
                            <td class="num font-semibold">{{ i.counted_qty ?? '–' }}</td>
                            <td class="num font-display text-lg font-bold" :class="i.variance < 0 ? 'text-bad' : i.variance > 0 ? 'text-ok' : ''">{{ i.variance > 0 ? '+' : '' }}{{ i.variance ?? '–' }}<div v-if="i.variance" class="font-sans text-[11.5px] font-medium">{{ i.variance < 0 ? `shortage of ${-i.variance}` : `excess of ${i.variance}` }}</div></td>
                            <td class="num text-muted">{{ i.movements_during_count || '–' }}</td>
                            <td class="num">{{ i.cost_impact !== undefined && i.cost_impact !== null ? formatMoney(i.cost_impact) : '–' }}</td>
                            <td class="text-[13px]">{{ i.counted_by ?? '–' }}<div class="text-muted">{{ formatDateTime(i.counted_at) }}</div></td>
                            <td v-if="editable" class="no-print min-w-[260px]">
                                <template v-if="i.variance !== 0">
                                    <div class="flex gap-3 text-sm">
                                        <label class="flex items-center gap-1"><input v-model="decisions[i.id].approve" type="radio" :value="true" :name="`d${i.id}`" /> Approve</label>
                                        <label class="flex items-center gap-1"><input v-model="decisions[i.id].approve" type="radio" :value="false" :name="`d${i.id}`" /> Reject</label>
                                        <label class="flex items-center gap-1"><input v-model="decisions[i.id].recount" type="checkbox" /> Recount</label>
                                    </div>
                                    <input v-model="decisions[i.id].reason" class="field mt-1 !min-h-9 !py-1 text-sm" placeholder="Reason (required to approve)" :aria-label="`Reason for ${i.product}`" />
                                </template>
                                <span v-else class="text-sm text-muted">No difference</span>
                            </td>
                            <td v-else><StatusBadge :status="i.status" /><div v-if="i.adjustment_reason" class="text-[12.5px] text-muted">{{ i.adjustment_reason }}</div></td>
                        </tr>
                        <tr v-if="!shown.length"><td colspan="9" class="py-8 text-center text-muted">No differences – the physical count matches the system.</td></tr>
                    </tbody>
                </table>
            </section>

            <div v-if="st.approved_at" class="panel p-5 text-sm">Approved by <strong>{{ st.approved_by }}</strong> on {{ formatDateTime(st.approved_at) }}. Adjustment reference <strong>{{ st.adjustment_reference }}</strong>.<p v-if="st.approval_notes" class="mt-1 text-muted">{{ st.approval_notes }}</p></div>

            <div v-if="editable" class="no-print grid gap-5 lg:grid-cols-2">
                <section class="panel p-5">
                    <h2 class="panel-title mb-2">Ask for a recount</h2>
                    <p class="mb-3 text-sm text-muted">{{ recountIds.length }} line(s) ticked. They go back to the counters and the stocktake re-opens.</p>
                    <input v-model="recountReason" class="field mb-2" placeholder="What should they check?" aria-label="Recount reason" />
                    <button type="button" class="btn btn-secondary" :disabled="!recountIds.length || !recountReason || submit.busy.value" @click="requestRecount"><RotateCcw class="size-4" /> Request recount</button>
                </section>
                <section class="panel p-5">
                    <h2 class="panel-title mb-2">Approve the stocktake</h2>
                    <p class="mb-3 text-sm text-muted">Approved differences become stocktake adjustment movements. Rejected differences leave stock unchanged. You cannot approve a count you took part in.</p>
                    <textarea v-model="notes" rows="2" class="field mb-2" placeholder="Approval notes (used as the reason where a line has none)" aria-label="Approval notes" />
                    <button type="button" class="btn btn-primary" :disabled="submit.busy.value" @click="approve"><CheckCheck class="size-4" /> Approve and post adjustments</button>
                </section>
            </div>
        </div>
    </AsyncState>
</template>
