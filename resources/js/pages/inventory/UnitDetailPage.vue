<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ClipboardCheck, ImagePlus } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import FormField from '@/components/ui/FormField.vue';
import { get, post, type ApiError } from '@/api/http';
import { useAuth } from '@/stores/auth';
import { useLookups } from '@/stores/lookups';
import { useUi } from '@/stores/ui';
import { useSubmit } from '@/composables/useForm';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';
import type { TyreUnit } from '@/types';

const route = useRoute();
const auth = useAuth();
const lookups = useLookups();
const ui = useUi();
const unit = ref<(TyreUnit & { movements: any[] }) | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const inspection = reactive({ result: 'available', grade: '', tread_depth_mm: '', inspection_notes: '' });
const submit = useSubmit();

async function load() {
    loading.value = true;
    try {
        unit.value = (await get(`/units/${route.params.id}`)).data;
        Object.assign(inspection, { result: unit.value!.status === 'rejected' ? 'rejected' : 'available', grade: unit.value!.grade ?? '', tread_depth_mm: unit.value!.tread_depth_mm ?? '', inspection_notes: unit.value!.inspection_notes ?? '' });
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function inspect() {
    const body = Object.fromEntries(Object.entries(inspection).map(([k, v]) => [k, v === '' ? null : v]));
    if (await submit.run(() => post(`/units/${route.params.id}/inspect`, body), 'Inspection recorded.')) load();
}

async function upload(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const data = new FormData();
    data.append('image', file);
    try {
        await post(`/units/${route.params.id}/images`, data);
        ui.toast('Photo uploaded.');
        load();
    } catch (err) {
        ui.toast((err as ApiError).message, 'error');
    }
}
</script>

<template>
    <AsyncState :loading="loading" :error="error" @retry="load">
        <div v-if="unit" class="max-w-5xl">
            <PageHeader :title="`Used tyre ${unit.unit_code}`" :back="`/inventory/${unit.product_id}`" :subtitle="unit.product?.display_name">
                <template #meta><div class="mt-2"><StatusBadge :status="unit.status" /></div></template>
            </PageHeader>
            <div class="grid gap-5 lg:grid-cols-2">
                <section class="panel">
                    <div class="panel-head"><h2 class="panel-title">Tyre details</h2><span class="font-display text-2xl font-bold">{{ formatMoney(unit.selling_price) }}</span></div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 p-5 text-sm">
                        <dt class="text-muted">Tread depth</dt><dd>{{ unit.tread_depth_mm ? `${unit.tread_depth_mm} mm` : '–' }}</dd>
                        <dt class="text-muted">Grade</dt><dd>{{ unit.grade ?? 'Not graded' }}</dd>
                        <dt class="text-muted">DOT code</dt><dd>{{ unit.dot_code ?? '–' }}</dd>
                        <dt class="text-muted">Location</dt><dd>{{ unit.location?.name ?? '–' }}</dd>
                        <dt v-if="unit.cost_price" class="text-muted">Cost price</dt><dd v-if="unit.cost_price">{{ formatMoney(unit.cost_price) }}</dd>
                        <dt class="text-muted">Inspected</dt><dd>{{ unit.inspected_at ? `${formatDate(unit.inspected_at)} by ${unit.inspected_by}` : 'Not yet' }}</dd>
                        <dt class="text-muted">Defects</dt><dd>{{ unit.defects || '–' }}</dd>
                        <dt class="text-muted">Notes</dt><dd>{{ unit.inspection_notes || '–' }}</dd>
                    </dl>
                    <div class="flex flex-wrap gap-2 border-t border-line-soft p-4">
                        <img v-for="m in unit.images" :key="m.id" :src="m.url" :alt="m.name" class="size-24 rounded-[6px] object-cover" />
                        <label v-if="auth.can('inventory.manage')" class="flex size-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-[6px] border border-dashed border-line text-xs text-muted hover:bg-surface">
                            <ImagePlus class="size-5" /> Add photo<input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="sr-only" @change="upload" />
                        </label>
                    </div>
                </section>

                <section v-if="auth.can('used.inspect') && ['pending_inspection', 'available', 'rejected'].includes(unit.status)" class="panel">
                    <div class="panel-head"><h2 class="panel-title">Inspection</h2></div>
                    <form class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="inspect">
                        <fieldset class="sm:col-span-2">
                            <legend class="field-label">Result</legend>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <label v-for="o in [{ v: 'available', l: 'Passed – sellable' }, { v: 'pending_inspection', l: 'Still pending' }, { v: 'rejected', l: 'Rejected – not for sale' }]" :key="o.v" class="flex min-h-11 cursor-pointer items-center gap-2 rounded-[6px] border px-3 text-sm font-semibold" :class="inspection.result === o.v ? 'border-asphalt bg-asphalt text-white' : 'border-line'">
                                    <input v-model="inspection.result" type="radio" :value="o.v" class="sr-only" /> {{ o.l }}
                                </label>
                            </div>
                        </fieldset>
                        <FormField v-slot="{ id }" label="Grade">
                            <select :id="id" v-model="inspection.grade" class="field"><option value="">Not graded</option><option v-for="g in lookups.settings?.used_tyre_grades" :key="g" :value="g">Grade {{ g }}</option></select>
                        </FormField>
                        <FormField v-slot="{ id }" label="Tread depth (mm)"><input :id="id" v-model="inspection.tread_depth_mm" type="number" step="0.1" min="0" class="field" /></FormField>
                        <div class="sm:col-span-2"><FormField v-slot="{ id }" label="Inspection notes"><textarea :id="id" v-model="inspection.inspection_notes" rows="2" class="field" /></FormField></div>
                        <p class="text-[13px] text-muted sm:col-span-2">A grade is an internal classification and does not certify roadworthiness.</p>
                        <div><button class="btn btn-dark" :disabled="submit.busy.value"><ClipboardCheck class="size-4" /> Save inspection</button></div>
                    </form>
                </section>

                <section class="panel lg:col-span-2">
                    <div class="panel-head"><h2 class="panel-title">History</h2></div>
                    <table class="data-table">
                        <thead><tr><th>Date</th><th>Type</th><th>Location</th><th class="num">Change</th><th>Reference</th><th>By</th></tr></thead>
                        <tbody>
                            <tr v-for="m in unit.movements" :key="m.id"><td>{{ formatDateTime(m.created_at) }}</td><td>{{ m.type_label }}</td><td>{{ m.location }}</td><td class="num">{{ m.quantity_change > 0 ? '+' : '' }}{{ m.quantity_change }}</td><td>{{ m.reference_no }}</td><td>{{ m.user }}</td></tr>
                            <tr v-if="!unit.movements.length"><td colspan="6" class="py-6 text-center text-muted">No movements.</td></tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AsyncState>
</template>
