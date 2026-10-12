<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Pencil, Send, Undo2, Paperclip, Printer, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import { del, get, post, type ApiError } from '@/api/http';
import { useAuth } from '@/stores/auth';
import { useUi } from '@/stores/ui';
import { useSubmit } from '@/composables/useForm';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const ui = useUi();
const receipt = ref<any>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const submit = useSubmit();
const reverseOpen = ref(false);
const reason = ref('');

async function load() {
    loading.value = true;
    try {
        receipt.value = (await get(`/receipts/${route.params.id}`)).data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function postReceipt(allow = false) {
    const res = await submit.run(() => post(`/receipts/${route.params.id}/post`, { allow_duplicate_reference: allow }), 'Delivery posted. Stock updated.');
    if (res) receipt.value = res.data;
    else if (submit.error.value?.code === 'duplicate_reference' && confirm(`${submit.error.value.message}\n\nPost anyway?`)) postReceipt(true);
}
async function reverse() {
    const res = await submit.run(() => post(`/receipts/${route.params.id}/reverse`, { reason: reason.value }), 'Receipt reversed. Stock reduced again.');
    if (res) {
        receipt.value = res.data;
        reverseOpen.value = false;
    }
}
const printPage = () => window.print();

async function remove() {
    if (!confirm('Delete this draft? This cannot be undone.')) return;
    if (await submit.run(() => del(`/receipts/${route.params.id}`), 'Draft deleted.')) router.replace('/receiving');
}
async function attach(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const data = new FormData();
    data.append('file', file);
    try {
        await post(`/receipts/${route.params.id}/attachments`, data);
        ui.toast('Document attached.');
        load();
    } catch (err) {
        ui.toast((err as ApiError).field('file') ?? (err as ApiError).message, 'error');
    }
}
</script>

<template>
    <AsyncState :loading="loading" :error="error" @retry="load">
        <div v-if="receipt" class="max-w-5xl">
            <PageHeader :title="`Receipt ${receipt.receipt_no}`" back="/receiving">
                <template #meta><div class="mt-2 flex items-center gap-2"><StatusBadge :status="receipt.status" /><span class="text-sm text-muted">{{ receipt.supplier?.name }}</span></div></template>
                <template #actions>
                    <template v-if="receipt.status === 'draft'">
                        <RouterLink v-if="auth.can('receiving.create')" :to="`/receiving/${receipt.id}/edit`" class="btn btn-secondary"><Pencil class="size-4" /> Edit draft</RouterLink>
                        <button v-if="auth.can('receiving.post')" type="button" class="btn btn-primary" :disabled="submit.busy.value" @click="postReceipt()"><Send class="size-4" /> Post delivery</button>
                        <button v-if="auth.can('receiving.create')" type="button" class="btn btn-ghost text-bad" @click="remove"><Trash2 class="size-4" /> Delete</button>
                    </template>
                    <button v-if="receipt.status === 'posted' && auth.can('receiving.reverse')" type="button" class="btn btn-secondary" @click="reverseOpen = true"><Undo2 class="size-4" /> Reverse</button>
                    <button type="button" class="btn btn-secondary" @click="printPage"><Printer class="size-4" /> Print</button>
                </template>
            </PageHeader>

            <p v-if="receipt.status === 'draft'" class="mb-4 rounded-[8px] bg-warn-soft px-4 py-3 text-sm text-warn">This is a draft. Stock has not been updated yet.</p>
            <p v-if="receipt.status === 'reversed'" class="mb-4 rounded-[8px] bg-bad-soft px-4 py-3 text-sm text-bad">Reversed {{ formatDateTime(receipt.reversed_at) }} by {{ receipt.reversed_by }}: {{ receipt.reversal_reason }}</p>

            <section class="panel mb-5">
                <dl class="grid gap-x-6 gap-y-3 p-5 text-sm sm:grid-cols-4">
                    <div><dt class="text-muted">Supplier</dt><dd class="font-semibold">{{ receipt.supplier?.name }}</dd></div>
                    <div><dt class="text-muted">Invoice / delivery note</dt><dd class="font-semibold">{{ receipt.supplier_reference ?? '–' }}</dd></div>
                    <div><dt class="text-muted">Delivery date</dt><dd class="font-semibold">{{ formatDate(receipt.delivery_date) }}</dd></div>
                    <div><dt class="text-muted">Posted</dt><dd class="font-semibold">{{ receipt.posted_at ? `${formatDateTime(receipt.posted_at)} by ${receipt.posted_by}` : '–' }}</dd></div>
                    <div><dt class="text-muted">Captured by</dt><dd>{{ receipt.created_by }}</dd></div>
                    <div class="sm:col-span-3"><dt class="text-muted">Notes</dt><dd>{{ receipt.notes || '–' }}</dd></div>
                </dl>
            </section>

            <section class="panel mb-5 overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Tyre</th><th>Location</th><th class="num">Qty</th><th v-if="receipt.total_cost !== null" class="num">Cost each</th><th v-if="receipt.total_cost !== null" class="num">Line total</th></tr></thead>
                    <tbody>
                        <template v-for="i in receipt.items" :key="i.id">
                            <tr>
                                <td><RouterLink :to="`/inventory/${i.product_id}`" class="font-semibold hover:underline">{{ i.product }}</RouterLink> <span class="text-[12.5px] text-muted">{{ i.condition === 'used' ? 'Used' : 'New' }} · {{ i.sku }}</span></td>
                                <td>{{ i.location }}</td>
                                <td class="num font-semibold">{{ i.quantity }}</td>
                                <td v-if="receipt.total_cost !== null" class="num">{{ formatMoney(i.unit_cost) }}</td>
                                <td v-if="receipt.total_cost !== null" class="num">{{ formatMoney(i.line_total) }}</td>
                            </tr>
                            <tr v-if="i.unit_details?.length"><td colspan="5" class="bg-surface text-[13px] text-muted">
                                <span v-for="(d, j) in i.unit_details" :key="j" class="mr-4 inline-block">#{{ Number(j) + 1 }}: {{ d.tread_depth_mm ?? '–' }} mm, grade {{ d.grade ?? '–' }}, DOT {{ d.dot_code ?? '–' }}, {{ formatMoney(d.selling_price) }}</span>
                            </td></tr>
                        </template>
                    </tbody>
                    <tfoot><tr><td colspan="2" class="px-3 py-3 font-semibold">Total</td><td class="num px-3 font-semibold">{{ receipt.total_quantity }}</td><td v-if="receipt.total_cost !== null" /><td v-if="receipt.total_cost !== null" class="num px-3 font-semibold">{{ formatMoney(receipt.total_cost) }}</td></tr></tfoot>
                </table>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <h2 class="panel-title">Supporting documents</h2>
                    <label v-if="auth.can('receiving.create')" class="btn btn-secondary btn-sm cursor-pointer"><Paperclip class="size-4" /> Attach<input type="file" accept="application/pdf,image/*" class="sr-only" @change="attach" /></label>
                </div>
                <ul class="divide-y divide-line-soft text-sm">
                    <li v-for="a in receipt.attachments" :key="a.id" class="px-5 py-3"><a :href="a.url" target="_blank" rel="noopener" class="link">{{ a.name }}</a></li>
                    <li v-if="!receipt.attachments?.length" class="px-5 py-4 text-muted">No documents. Attach the invoice or delivery note (PDF or photo, max 10 MB).</li>
                </ul>
            </section>

            <ModalDialog :open="reverseOpen" title="Reverse this receipt" @close="reverseOpen = false">
                <p class="mb-3 text-sm text-muted">Reversing removes the received tyres from stock again with a traceable reversal movement. The original receipt stays on record. It fails if some of the tyres have already been sold.</p>
                <label for="rev-reason" class="field-label">Reason</label>
                <textarea id="rev-reason" v-model="reason" rows="3" class="field" placeholder="e.g. Captured against the wrong supplier" />
                <p v-if="submit.fieldError('reason')" class="field-error">{{ submit.fieldError('reason') }}</p>
                <template #footer>
                    <button type="button" class="btn btn-secondary" @click="reverseOpen = false">Cancel</button>
                    <button type="button" class="btn btn-danger" :disabled="reason.length < 5 || submit.busy.value" @click="reverse">Reverse receipt</button>
                </template>
            </ModalDialog>
        </div>
    </AsyncState>
</template>
