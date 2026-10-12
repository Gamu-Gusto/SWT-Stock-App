<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Download, Printer, FileSpreadsheet } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import { downloadUrl, get, type ApiError } from '@/api/http';
import { useLookups } from '@/stores/lookups';
import { daysAgoIso, formatDate, formatDateTime, formatMoney, todayIso } from '@/utils/format';

const route = useRoute();
const lookups = useLookups();
const key = String(route.params.key);
const report = ref<any>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const filters = reactive<Record<string, any>>({
    date_from: daysAgoIso(29), date_to: todayIso(), brand_id: '', size: '', condition: '', location_id: '', supplier_id: '', user_id: '',
    group_by: 'date', days: lookups.settings?.slow_moving_days ?? 90, movement_type: '',
});

const usesDates = ['stock_movements', 'sales', 'stocktake_variances'].includes(key);
async function load() {
    loading.value = true;
    error.value = null;
    try {
        report.value = (await get(`/reports/${key}`, params())).data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
function params() {
    const p: Record<string, any> = { ...filters };
    if (key !== 'sales') delete p.group_by;
    if (key !== 'slow_moving') delete p.days;
    if (key !== 'stock_movements') delete p.movement_type;
    return p;
}
let t: ReturnType<typeof setTimeout> | undefined;
watch(filters, () => {
    clearTimeout(t);
    t = setTimeout(load, 350);
});
onMounted(load);

const printPage = () => window.print();
function cell(value: any, type: string) {
    if (value === null || value === undefined || value === '') return '–';
    if (type === 'money') return formatMoney(value);
    if (type === 'date') return formatDate(value);
    if (type === 'datetime') return formatDateTime(value);
    if (type === 'signed') return value > 0 ? `+${value}` : String(value);
    return value;
}
const numeric = (type: string) => ['money', 'int', 'signed'].includes(type);
const exportLink = (format: string) => downloadUrl(`/reports/${key}/export`, { ...params(), format });
const rows = computed(() => report.value?.rows ?? []);
</script>

<template>
    <div>
        <PageHeader :title="report?.title ?? 'Report'" back="/reports" :subtitle="report ? `Generated ${formatDateTime(report.generated_at)}${usesDates ? ` · ${formatDate(filters.date_from)} – ${formatDate(filters.date_to)}` : ''}` : undefined">
            <template #actions>
                <a :href="exportLink('csv')" class="btn btn-secondary"><Download class="size-4" /> CSV</a>
                <a :href="exportLink('xlsx')" class="btn btn-secondary"><FileSpreadsheet class="size-4" /> Excel</a>
                <button type="button" class="btn btn-secondary" @click="printPage"><Printer class="size-4" /> Print</button>
            </template>
        </PageHeader>

        <div class="no-print mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
            <template v-if="usesDates">
                <div><label class="field-label" for="rf-from">From</label><input id="rf-from" v-model="filters.date_from" type="date" class="field" /></div>
                <div><label class="field-label" for="rf-to">To</label><input id="rf-to" v-model="filters.date_to" type="date" class="field" /></div>
            </template>
            <div v-if="key === 'sales'"><label class="field-label" for="rf-g">Group by</label><select id="rf-g" v-model="filters.group_by" class="field"><option value="date">Date</option><option value="employee">Employee</option><option value="brand">Brand</option><option value="size">Size</option><option value="condition">Condition</option></select></div>
            <div v-if="key === 'slow_moving'"><label class="field-label" for="rf-d">No sales for</label><select id="rf-d" v-model="filters.days" class="field"><option :value="30">30 days</option><option :value="60">60 days</option><option :value="90">90 days</option><option :value="180">180 days</option><option :value="365">1 year</option></select></div>
            <div v-if="key === 'stock_movements'"><label class="field-label" for="rf-mt">Type</label><select id="rf-mt" v-model="filters.movement_type" class="field"><option value="">All</option><option v-for="(l, k) in lookups.movementTypes" :key="k" :value="k">{{ l }}</option></select></div>
            <div><label class="field-label" for="rf-b">Brand</label><select id="rf-b" v-model="filters.brand_id" class="field"><option value="">All</option><option v-for="b in lookups.brands" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
            <div><label class="field-label" for="rf-s">Size</label><input id="rf-s" v-model="filters.size" class="field" placeholder="205/55R16 or R16" /></div>
            <div><label class="field-label" for="rf-c">Condition</label><select id="rf-c" v-model="filters.condition" class="field"><option value="">All</option><option value="new">New</option><option value="used">Used</option></select></div>
            <div><label class="field-label" for="rf-l">Location</label><select id="rf-l" v-model="filters.location_id" class="field"><option value="">All</option><option v-for="l in lookups.locations" :key="l.id" :value="l.id">{{ l.name }}</option></select></div>
            <div v-if="lookups.suppliers.length && !usesDates"><label class="field-label" for="rf-sup">Supplier</label><select id="rf-sup" v-model="filters.supplier_id" class="field"><option value="">All</option><option v-for="s in lookups.suppliers" :key="s.id" :value="s.id">{{ s.company_name }}</option></select></div>
            <div v-if="lookups.employees.length && ['sales', 'stock_movements'].includes(key)"><label class="field-label" for="rf-u">Employee</label><select id="rf-u" v-model="filters.user_id" class="field"><option value="">Everyone</option><option v-for="e in lookups.employees" :key="e.id" :value="e.id">{{ e.name }}</option></select></div>
        </div>

        <div class="print-only mb-4">
            <p class="font-display text-xl font-bold">{{ lookups.settings?.business_name }}</p>
        </div>

        <AsyncState :loading="loading && !report" :error="error" @retry="load">
            <template v-if="report">
                <div v-if="Object.keys(report.summary).length" class="mb-4 flex flex-wrap gap-x-8 gap-y-2 rounded-[10px] border border-line bg-panel px-5 py-4">
                    <div v-for="(v, k) in report.summary" :key="k"><p class="text-[13px] text-muted">{{ k }}</p><p class="font-display text-xl font-semibold">{{ v }}</p></div>
                </div>
                <section class="panel overflow-x-auto" :class="{ 'opacity-60': loading }">
                    <table class="data-table">
                        <thead><tr><th v-for="c in report.columns" :key="c.key" :class="{ num: numeric(c.type) }">{{ c.label }}</th></tr></thead>
                        <tbody>
                            <tr v-for="(r, i) in rows" :key="i">
                                <td v-for="c in report.columns" :key="c.key" :class="[{ num: numeric(c.type) }, c.type === 'signed' && r[c.key] < 0 ? 'text-bad' : c.type === 'signed' && r[c.key] > 0 ? 'text-ok' : '']">{{ cell(r[c.key], c.type) }}</td>
                            </tr>
                            <tr v-if="!rows.length"><td :colspan="report.columns.length" class="py-10 text-center text-muted">No data for these filters.</td></tr>
                        </tbody>
                    </table>
                </section>
                <p v-if="key === 'inventory_valuation'" class="mt-3 text-sm text-muted">Valuation method: first in, first out (FIFO) for new tyres – the remaining quantity of each delivery at its purchase cost; used tyres at the specific cost of each tyre.</p>
            </template>
        </AsyncState>
    </div>
</template>
