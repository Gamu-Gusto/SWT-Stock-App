<script setup lang="ts">
import { computed, ref } from 'vue';
import { Upload, Download, CircleCheck, CircleAlert, Copy } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import { downloadUrl, post } from '@/api/http';
import { useSubmit } from '@/composables/useForm';
import { useLookups } from '@/stores/lookups';

const lookups = useLookups();
const submit = useSubmit();
const step = ref<1 | 2 | 3 | 4>(1);
const imp = ref<any>(null);
const mapping = ref<Record<string, number | null>>({});
const duplicateMode = ref<'skip' | 'add'>('skip');
const defaultLocation = ref<number | ''>('');
const usedStatus = ref<'pending_inspection' | 'available'>('pending_inspection');
const preview = ref<any>(null);
const filter = ref<'all' | 'valid' | 'duplicate' | 'invalid'>('all');
const result = ref<any>(null);

async function upload(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const data = new FormData();
    data.append('file', file);
    const res = await submit.run(() => post('/imports', data));
    if (res) {
        imp.value = res.data;
        mapping.value = { ...res.data.mapping };
        step.value = 2;
    }
}
const options = () => ({ mapping: mapping.value, duplicate_mode: duplicateMode.value, default_location_id: defaultLocation.value || null, used_status: usedStatus.value });
async function runPreview() {
    const res = await submit.run(() => post(`/imports/${imp.value.id}/preview`, options()));
    if (res) {
        preview.value = res.data;
        step.value = 3;
    }
}
async function commit() {
    if (!confirm(`Import ${preview.value.summary.quantity_to_import} tyres? Each creates an opening-balance stock movement.`)) return;
    const res = await submit.run(() => post(`/imports/${imp.value.id}/commit`, options()), 'Import completed.');
    if (res) {
        result.value = res.data.summary;
        step.value = 4;
    }
}
const shownRows = computed(() => (preview.value?.rows ?? []).filter((r: any) => filter.value === 'all' || r.status === filter.value));
const requiredMissing = computed(() => (imp.value?.fields ?? []).filter((f: any) => f.required && (mapping.value[f.key] === null || mapping.value[f.key] === undefined)).map((f: any) => f.label));
</script>

<template>
    <div class="max-w-6xl">
        <PageHeader title="Stock import" subtitle="Bring existing stock lists in from Excel or CSV. Nothing is saved until you confirm the preview, and existing quantities are never overwritten.">
            <template #actions><a :href="downloadUrl('/imports/template')" class="btn btn-secondary"><Download class="size-4" /> Download template</a></template>
        </PageHeader>

        <ol class="mb-5 flex flex-wrap gap-2 text-sm" aria-label="Import steps">
            <li v-for="(s, i) in ['Upload', 'Match columns', 'Check preview', 'Done']" :key="s" class="rounded-full px-3 py-1 font-semibold" :class="step === Number(i) + 1 ? 'bg-asphalt text-white' : step > Number(i) + 1 ? 'bg-ok-soft text-ok' : 'bg-line-soft text-muted'">{{ Number(i) + 1 }}. {{ s }}</li>
        </ol>

        <section v-if="step === 1" class="panel p-8 text-center">
            <Upload class="mx-auto mb-3 size-10 text-faint" />
            <p class="font-display text-xl font-semibold">Upload a CSV or XLSX file</p>
            <p class="mt-1 text-sm text-muted">First row must contain column headings. Up to 5 000 rows, 5 MB.</p>
            <label class="btn btn-primary mt-4 cursor-pointer">{{ submit.busy.value ? 'Reading…' : 'Choose file' }}<input type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="sr-only" @change="upload" /></label>
            <p v-if="submit.error.value" class="field-error mt-3">{{ submit.error.value.field('file') ?? submit.error.value.message }}</p>
        </section>

        <section v-if="step === 2" class="panel p-5">
            <h2 class="panel-title mb-1">Match your columns</h2>
            <p class="mb-4 text-sm text-muted">{{ imp.original_name }} – we matched the headings we recognised. Check each field.</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label v-for="f in imp.fields" :key="f.key">
                    <span class="field-label">{{ f.label }}<span v-if="f.required" class="text-bad"> *</span></span>
                    <select v-model="mapping[f.key]" class="field"><option :value="null">— not in file —</option><option v-for="(h, i) in imp.headers" :key="i" :value="i">{{ h || `Column ${Number(i) + 1}` }}</option></select>
                </label>
            </div>
            <div class="mt-5 grid gap-4 border-t border-line-soft pt-5 sm:grid-cols-3">
                <fieldset>
                    <legend class="field-label">Tyres that already exist</legend>
                    <label class="flex items-center gap-2 text-sm"><input v-model="duplicateMode" type="radio" value="skip" /> Skip them (recommended)</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="duplicateMode" type="radio" value="add" /> Add the file quantity to current stock</label>
                </fieldset>
                <label><span class="field-label">Location when the file has none</span><select v-model="defaultLocation" class="field"><option value="">None – rows need a location</option><option v-for="l in lookups.activeLocations" :key="l.id" :value="l.id">{{ l.name }}</option></select></label>
                <label><span class="field-label">Imported used tyres</span><select v-model="usedStatus" class="field"><option value="pending_inspection">Need inspection before sale (safer)</option><option value="available">Already inspected – sellable</option></select></label>
            </div>
            <p v-if="requiredMissing.length" class="field-error mt-3">Map the required fields: {{ requiredMissing.join(', ') }}.</p>
            <div class="mt-5 flex gap-2"><button type="button" class="btn btn-primary" :disabled="submit.busy.value || requiredMissing.length > 0" @click="runPreview">Preview import</button><button type="button" class="btn btn-secondary" @click="step = 1">Start again</button></div>
        </section>

        <section v-if="step === 3 && preview">
            <div class="mb-4 grid grid-cols-2 overflow-hidden rounded-[10px] border border-line bg-panel sm:grid-cols-5">
                <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Rows</p><p class="qty !text-3xl">{{ preview.summary.total_rows }}</p></div>
                <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Ready</p><p class="qty !text-3xl text-ok">{{ preview.summary.valid }}</p></div>
                <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Duplicates</p><p class="qty !text-3xl text-warn">{{ preview.summary.duplicates }}</p></div>
                <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">With errors</p><p class="qty !text-3xl text-bad">{{ preview.summary.invalid }}</p></div>
                <div class="p-4"><p class="text-sm text-muted">Tyres to import</p><p class="qty !text-3xl">{{ preview.summary.quantity_to_import }}</p></div>
            </div>
            <div class="mb-3 flex flex-wrap gap-2">
                <button v-for="f in ['all', 'valid', 'duplicate', 'invalid'] as const" :key="f" type="button" class="btn btn-sm" :class="filter === f ? 'btn-dark' : 'btn-secondary'" @click="filter = f">{{ { all: 'All rows', valid: 'Ready', duplicate: 'Duplicates', invalid: 'Errors' }[f] }}</button>
            </div>
            <section class="panel mb-4 max-h-[60vh] overflow-auto">
                <table class="data-table">
                    <thead class="sticky top-0"><tr><th>Row</th><th></th><th>Brand / model</th><th>Size</th><th>Cond.</th><th class="num">Qty</th><th class="num">Price</th><th>Location</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr v-for="r in shownRows" :key="r.line">
                            <td>{{ r.line }}</td>
                            <td><CircleCheck v-if="r.status === 'valid'" class="size-4 text-ok" aria-label="Ready" /><Copy v-else-if="r.status === 'duplicate'" class="size-4 text-warn" aria-label="Duplicate" /><CircleAlert v-else class="size-4 text-bad" aria-label="Error" /></td>
                            <td>{{ r.data.brand }} {{ r.data.model }}</td>
                            <td>{{ r.data.size }}</td>
                            <td>{{ r.data.condition ?? '?' }}</td>
                            <td class="num">{{ r.data.quantity }}</td>
                            <td class="num">{{ r.data.selling_price }}</td>
                            <td>{{ r.data.location }}</td>
                            <td class="text-[13px]"><p v-for="e in r.errors" :key="e" class="text-bad">{{ e }}</p><p v-for="w in r.warnings" :key="w" class="text-warn">{{ w }}</p></td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" :disabled="submit.busy.value || preview.summary.quantity_to_import + preview.summary.valid === 0" @click="commit">Import {{ preview.summary.quantity_to_import }} tyres</button>
                <button type="button" class="btn btn-secondary" @click="step = 2">Back to columns</button>
            </div>
            <p class="mt-2 text-sm text-muted">Rows with errors are left out. Fix them in the file and import that file separately.</p>
        </section>

        <section v-if="step === 4 && result" class="panel p-6">
            <h2 class="panel-title mb-3">Import summary</h2>
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-muted">New tyre records</dt><dd class="qty">{{ result.products_created }}</dd></div>
                <div><dt class="text-muted">Added to existing</dt><dd class="qty">{{ result.quantities_added_to_existing }}</dd></div>
                <div><dt class="text-muted">Total tyres imported</dt><dd class="qty">{{ result.total_quantity_imported }}</dd></div>
                <div><dt class="text-muted">Skipped</dt><dd class="qty">{{ result.skipped }}</dd></div>
                <div><dt class="text-muted">Duplicates found</dt><dd class="qty">{{ result.duplicates }}</dd></div>
                <div><dt class="text-muted">Invalid rows</dt><dd class="qty">{{ result.invalid }}</dd></div>
            </dl>
            <div class="mt-5 flex gap-2"><RouterLink to="/inventory" class="btn btn-primary">View inventory</RouterLink><button type="button" class="btn btn-secondary" @click="step = 1; imp = null; preview = null">Import another file</button></div>
        </section>
    </div>
</template>
