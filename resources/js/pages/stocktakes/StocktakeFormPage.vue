<script setup lang="ts">
import { computed, reactive } from 'vue';
import { useRouter } from 'vue-router';
import PageHeader from '@/components/ui/PageHeader.vue';
import FormField from '@/components/ui/FormField.vue';
import { post } from '@/api/http';
import { useLookups } from '@/stores/lookups';
import { useSubmit } from '@/composables/useForm';

const router = useRouter();
const lookups = useLookups();
const submit = useSubmit();
const form = reactive({
    title: '',
    type: 'location' as 'full' | 'brand' | 'size' | 'location' | 'used',
    brand_ids: [] as number[],
    width: '', profile: '', rim: '',
    location_id: '' as number | '',
    is_blind: true,
    notes: '',
    assignee_ids: [] as number[],
    start: true,
});
const types = [
    { v: 'location', l: 'One storage location', d: 'Count everything on a rack or in an area.' },
    { v: 'brand', l: 'Selected brands', d: 'Count every tyre of the chosen brands.' },
    { v: 'size', l: 'A tyre size', d: 'Count every tyre of a size, e.g. all R16.' },
    { v: 'used', l: 'Used tyres', d: 'Check every individually tracked used tyre.' },
    { v: 'full', l: 'Full inventory', d: 'Everything in every location.' },
] as const;
const counters = computed(() => lookups.employees);

async function save() {
    const body = { ...form, width: form.width || null, profile: form.profile || null, rim: form.rim || null, location_id: form.location_id || null, title: form.title || types.find((t) => t.v === form.type)!.l };
    const res = await submit.run(() => post('/stocktakes', body), form.start ? 'Stocktake started. Counters can begin.' : 'Stocktake saved as a draft.');
    if (res) router.replace(`/stocktakes/${res.data.id}`);
}
</script>

<template>
    <div class="max-w-3xl">
        <PageHeader title="New stocktake" back="/stocktakes" subtitle="Choose what to count and who counts it. Starting the count takes a snapshot of the system quantities." />
        <form class="space-y-5" @submit.prevent="save">
            <section class="panel p-5">
                <fieldset>
                    <legend class="panel-title mb-3">What are you counting?</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="t in types" :key="t.v" class="flex cursor-pointer gap-3 rounded-[8px] border p-3" :class="form.type === t.v ? 'border-asphalt ring-2 ring-asphalt/15' : 'border-line'">
                            <input v-model="form.type" type="radio" :value="t.v" class="mt-1 size-4" />
                            <span><span class="block font-semibold">{{ t.l }}</span><span class="text-sm text-muted">{{ t.d }}</span></span>
                        </label>
                    </div>
                </fieldset>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ id }" :label="form.type === 'location' ? 'Location' : 'Limit to location (optional)'" :required="form.type === 'location'" :error="submit.fieldError('location_id')">
                        <select :id="id" v-model="form.location_id" class="field"><option value="">{{ form.type === 'location' ? 'Choose' : 'All locations' }}</option><option v-for="l in lookups.activeLocations" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                    </FormField>
                    <div v-if="form.type === 'size'" class="grid grid-cols-3 gap-2">
                        <FormField v-slot="{ id }" label="Width"><input :id="id" v-model="form.width" type="number" class="field" placeholder="205" /></FormField>
                        <FormField v-slot="{ id }" label="Profile"><input :id="id" v-model="form.profile" type="number" class="field" placeholder="55" /></FormField>
                        <FormField v-slot="{ id }" label="Rim"><input :id="id" v-model="form.rim" type="number" class="field" placeholder="16" /></FormField>
                    </div>
                    <fieldset v-if="form.type === 'brand'" class="sm:col-span-2">
                        <legend class="field-label">Brands</legend>
                        <div class="flex flex-wrap gap-2">
                            <label v-for="b in lookups.brands" :key="b.id" class="flex items-center gap-2 rounded-[6px] border border-line px-3 py-2 text-sm"><input v-model="form.brand_ids" type="checkbox" :value="b.id" class="size-4" /> {{ b.name }}</label>
                        </div>
                    </fieldset>
                </div>
            </section>

            <section class="panel grid gap-4 p-5 sm:grid-cols-2">
                <FormField v-slot="{ id }" label="Title" hint="e.g. Rack A month-end count"><input :id="id" v-model="form.title" class="field" /></FormField>
                <FormField v-slot="{ id }" label="Notes"><input :id="id" v-model="form.notes" class="field" /></FormField>
                <fieldset class="sm:col-span-2">
                    <legend class="field-label">Who will count?</legend>
                    <div class="flex flex-wrap gap-2">
                        <label v-for="u in counters" :key="u.id" class="flex items-center gap-2 rounded-[6px] border border-line px-3 py-2 text-sm"><input v-model="form.assignee_ids" type="checkbox" :value="u.id" class="size-4" /> {{ u.name }} <span class="text-muted">{{ u.role }}</span></label>
                    </div>
                    <p v-if="submit.fieldError('assignee_ids.0') || submit.error.value?.code === 'no_assignees'" class="field-error">Assign at least one employee who can count stock.</p>
                </fieldset>
                <label class="flex items-start gap-2 text-sm sm:col-span-2"><input v-model="form.is_blind" type="checkbox" class="mt-0.5 size-5" /> <span><strong>Blind count</strong> – counters do not see the expected system quantity, so they count what is really there.</span></label>
                <label class="flex items-start gap-2 text-sm sm:col-span-2"><input v-model="form.start" type="checkbox" class="mt-0.5 size-5" /> <span><strong>Start counting now</strong> – takes the snapshot and {{ lookups.settings?.stocktake_lock_mode === 'block' ? 'pauses sales, receipts and transfers of the counted stock until the count is submitted' : 'records any sales or receipts that happen during the count' }}.</span></label>
            </section>
            <button type="submit" class="btn btn-primary" :disabled="submit.busy.value || !form.assignee_ids.length">{{ form.start ? 'Start stocktake' : 'Save draft' }}</button>
        </form>
    </div>
</template>
