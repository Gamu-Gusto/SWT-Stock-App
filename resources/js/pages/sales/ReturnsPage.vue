<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import TabLinks from '@/components/ui/TabLinks.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import { get, post, type ApiError } from '@/api/http';
import { useLookups } from '@/stores/lookups';
import { useSubmit } from '@/composables/useForm';
import { formatDateTime } from '@/utils/format';

const lookups = useLookups();
const items = ref<any[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const submit = useSubmit();
const decisions = reactive<Record<number, { outcome: string; location: number | ''; notes: string }>>({});

async function load() {
    loading.value = true;
    try {
        items.value = (await get('/returns/pending')).data;
        for (const i of items.value) decisions[i.id] ??= { outcome: 'restocked', location: lookups.sellableLocations[0]?.id ?? '', notes: '' };
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function save(id: number) {
    const d = decisions[id];
    const ok = await submit.run(() => post(`/return-items/${id}/inspect`, { outcome: d.outcome, restock_location_id: d.outcome === 'restocked' ? d.location : null, notes: d.notes || null }), 'Inspection recorded.');
    if (ok) load();
}
</script>

<template>
    <div>
        <PageHeader title="Sales" subtitle="Returned tyres waiting for inspection. They are physically here but cannot be sold until you decide." />
        <TabLinks :tabs="[{ label: 'Sales', to: '/sales' }, { label: 'Returns to inspect', to: '/sales/returns' }]" />
        <AsyncState :loading="loading" :error="error" :empty="!items.length" empty-title="Nothing waiting for inspection" @retry="load">
            <div class="space-y-3">
                <section v-for="i in items" :key="i.id" class="panel p-4 sm:p-5">
                    <div class="flex flex-wrap justify-between gap-2">
                        <div>
                            <p class="font-semibold">{{ i.quantity }} × {{ i.product }} <span v-if="i.unit_code" class="text-muted">({{ i.unit_code }})</span></p>
                            <p class="text-sm text-muted">{{ i.return_no }} on <RouterLink :to="`/sales/${i.sale_id}`" class="link">{{ i.sale_no }}</RouterLink> · {{ i.customer_name ?? 'walk-in' }} · {{ formatDateTime(i.returned_at) }}</p>
                            <p class="text-sm">Reason: {{ i.reason }}</p>
                        </div>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-[1fr_1fr_2fr_auto] sm:items-end">
                        <label><span class="field-label">Outcome</span>
                            <select v-model="decisions[i.id].outcome" class="field"><option value="restocked">Passed – back into stock</option><option value="damaged">Damaged – move to damaged stock</option><option value="written_off">Write off</option></select>
                        </label>
                        <label v-if="decisions[i.id].outcome === 'restocked'"><span class="field-label">Restock to</span>
                            <select v-model="decisions[i.id].location" class="field"><option v-for="l in lookups.sellableLocations" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                        </label>
                        <span v-else />
                        <label><span class="field-label">Inspection notes</span><input v-model="decisions[i.id].notes" class="field" /></label>
                        <button type="button" class="btn btn-dark" :disabled="submit.busy.value" @click="save(i.id)">Save</button>
                    </div>
                </section>
            </div>
        </AsyncState>
    </div>
</template>
