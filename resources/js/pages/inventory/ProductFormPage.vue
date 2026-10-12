<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageHeader from '@/components/ui/PageHeader.vue';
import FormField from '@/components/ui/FormField.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import { get, post, put, type ApiError } from '@/api/http';
import { useLookups } from '@/stores/lookups';
import { useAuth } from '@/stores/auth';
import { useSubmit } from '@/composables/useForm';
import type { Product } from '@/types';

const route = useRoute();
const router = useRouter();
const lookups = useLookups();
const auth = useAuth();
const editing = computed(() => !!route.params.id);
const loading = ref(editing.value);
const loadError = ref<string | null>(null);
const submit = useSubmit();
const financial = auth.can('financials.view');

const form = reactive({
    brand_id: '' as number | '',
    tyre_model_id: '' as number | '',
    model_name: '',
    sku: '',
    description: '',
    condition: (route.query.condition === 'used' ? 'used' : 'new') as 'new' | 'used',
    width: '' as number | '',
    profile: '' as number | '',
    rim: '' as number | string,
    load_index: '',
    speed_rating: '',
    tyre_type: 'passenger',
    run_flat: false,
    cost_price: '',
    selling_price: '',
    promo_price: '',
    min_selling_price: '',
    fitting_fee: '',
    balancing_fee: '',
    alignment_fee: '',
    min_stock_level: lookups.settings?.default_min_stock ?? 4,
    reorder_qty: 0,
    default_location_id: '' as number | '',
    default_supplier_id: '' as number | '',
    is_public: true,
    opening_qty: '' as number | '',
    opening_location_id: '' as number | '',
});

const models = computed(() => lookups.brands.find((b) => b.id === form.brand_id)?.models ?? []);
const sizePreview = computed(() => {
    if (!form.width || !form.rim) return '';
    const rim = String(form.rim).replace(/\.0$/, '');
    return form.profile ? `${form.width}/${form.profile} R${rim}` : `${form.width} R${rim}`;
});

onMounted(async () => {
    if (!editing.value) return;
    try {
        const p = (await get<{ data: Product }>(`/products/${route.params.id}`)).data;
        Object.assign(form, {
            brand_id: p.brand_id, tyre_model_id: p.tyre_model_id ?? '', model_name: '', sku: p.sku, description: p.description ?? '',
            condition: p.condition, width: p.spec?.width ?? '', profile: p.spec?.profile ?? '', rim: p.spec?.rim ?? '',
            load_index: p.spec?.load_index ?? '', speed_rating: p.spec?.speed_rating ?? '', tyre_type: p.spec?.tyre_type ?? '',
            run_flat: p.spec?.run_flat ?? false, cost_price: p.cost_price ?? '', selling_price: p.selling_price, promo_price: p.promo_price ?? '',
            min_selling_price: p.min_selling_price ?? '', fitting_fee: p.fitting_fee ?? '', balancing_fee: p.balancing_fee ?? '',
            alignment_fee: p.alignment_fee ?? '', min_stock_level: p.min_stock_level, reorder_qty: p.reorder_qty,
            default_location_id: p.default_location_id ?? '', default_supplier_id: p.default_supplier_id ?? '', is_public: p.is_public,
        });
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
});

async function save() {
    const payload: Record<string, unknown> = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
    if (!payload.tyre_model_id) delete payload.tyre_model_id;
    if (!financial) delete payload.cost_price;
    if (editing.value) {
        delete payload.opening_qty;
        delete payload.opening_location_id;
    }
    if (!payload.sku) delete payload.sku;
    const res = await submit.run(
        () => (editing.value ? put<{ data: Product }>(`/products/${route.params.id}`, payload) : post<{ data: Product }>('/products', payload)),
        editing.value ? 'Tyre updated.' : 'Tyre added.',
    );
    if (res) router.replace(`/inventory/${res.data.id}`);
}

const err = (k: string) => submit.fieldError(k);
const duplicate = computed(() => (submit.error.value?.code === 'duplicate_product' ? submit.error.value.context : null));
</script>

<template>
    <div class="max-w-4xl">
        <PageHeader :title="editing ? 'Edit tyre' : 'Add tyre'" :back="editing ? `/inventory/${route.params.id}` : '/inventory'" subtitle="The full size is built from width, profile and rim. New and used tyres are always separate records." />
        <AsyncState :loading="loading" :error="loadError">
            <form class="space-y-5" novalidate @submit.prevent="save">
                <div v-if="duplicate" class="rounded-[10px] border border-warn/30 bg-warn-soft p-4 text-sm text-warn" role="alert">
                    {{ submit.error.value?.message }}
                    <RouterLink :to="`/inventory/${duplicate.product_id}`" class="link ml-1">Open {{ duplicate.sku }}</RouterLink>
                </div>

                <section class="panel">
                    <div class="panel-head"><h2 class="panel-title">Tyre</h2><span v-if="sizePreview" class="size-chip size-chip-lg">{{ sizePreview }}</span></div>
                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        <fieldset class="sm:col-span-2" :disabled="editing">
                            <legend class="field-label">Condition</legend>
                            <div class="grid max-w-md grid-cols-2 gap-2">
                                <label v-for="c in ['new', 'used']" :key="c" class="flex min-h-12 cursor-pointer items-center gap-2 rounded-[6px] border px-3 font-semibold" :class="form.condition === c ? 'border-asphalt bg-asphalt text-white' : 'border-line'">
                                    <input v-model="form.condition" type="radio" :value="c" class="sr-only" /> {{ c === 'new' ? 'New tyres (by quantity)' : 'Used tyres (each one tracked)' }}
                                </label>
                            </div>
                            <p v-if="form.condition === 'used'" class="field-hint">Used tyres are added one by one after saving, each with its own tread depth, grade, DOT code and price.</p>
                        </fieldset>
                        <FormField v-slot="{ id }" label="Brand" required :error="err('brand_id')">
                            <select :id="id" v-model="form.brand_id" class="field" required @change="form.tyre_model_id = ''">
                                <option value="" disabled>Choose brand</option>
                                <option v-for="b in lookups.activeBrands" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </FormField>
                        <FormField v-slot="{ id }" label="Model" :error="err('tyre_model_id') ?? err('model_name')" hint="Pick an existing model or type a new one">
                            <select v-if="models.length && form.model_name === ''" :id="id" v-model="form.tyre_model_id" class="field">
                                <option value="">Choose model…</option>
                                <option v-for="m in models" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </select>
                            <input v-if="!form.tyre_model_id" v-model="form.model_name" class="field mt-2" placeholder="New model name, e.g. Ziex ZE310" :aria-label="`${models.length ? 'Or new' : 'New'} model name`" />
                        </FormField>
                        <div class="grid grid-cols-3 gap-3 sm:col-span-2">
                            <FormField v-slot="{ id }" label="Width" required :error="err('width')"><input :id="id" v-model.number="form.width" type="number" inputmode="numeric" class="field" placeholder="205" /></FormField>
                            <FormField v-slot="{ id }" label="Profile" :error="err('profile')"><input :id="id" v-model.number="form.profile" type="number" inputmode="numeric" class="field" placeholder="55" /></FormField>
                            <FormField v-slot="{ id }" label="Rim (inches)" required :error="err('rim')"><input :id="id" v-model="form.rim" type="text" inputmode="decimal" class="field" placeholder="16" /></FormField>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <FormField v-slot="{ id }" label="Load index" :error="err('load_index')"><input :id="id" v-model="form.load_index" class="field" placeholder="91" /></FormField>
                            <FormField v-slot="{ id }" label="Speed rating" :error="err('speed_rating')"><input :id="id" v-model="form.speed_rating" class="field uppercase" maxlength="2" placeholder="V" /></FormField>
                        </div>
                        <FormField v-slot="{ id }" label="Tyre type" :error="err('tyre_type')">
                            <select :id="id" v-model="form.tyre_type" class="field"><option value="">Not specified</option><option v-for="(l, k) in lookups.tyreTypes" :key="k" :value="k">{{ l }}</option></select>
                        </FormField>
                        <label class="flex items-center gap-2 text-sm font-medium sm:col-span-2"><input v-model="form.run_flat" type="checkbox" class="size-5" /> Run flat tyre</label>
                        <FormField v-slot="{ id }" label="SKU" :error="err('sku')" hint="Leave blank to generate one, e.g. FAL-2055516-N-00012">
                            <input :id="id" v-model="form.sku" class="field" />
                        </FormField>
                        <FormField v-slot="{ id }" label="Description" :error="err('description')"><input :id="id" v-model="form.description" class="field" /></FormField>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-head"><h2 class="panel-title">Prices</h2><span class="text-sm text-muted">Rand, including VAT where applicable</span></div>
                    <div class="grid gap-4 p-5 sm:grid-cols-3">
                        <FormField v-slot="{ id }" label="Selling price per tyre" required :error="err('selling_price')"><input :id="id" v-model="form.selling_price" type="number" step="0.01" min="0" inputmode="decimal" class="field" /></FormField>
                        <FormField v-if="financial" v-slot="{ id }" label="Cost price" :error="err('cost_price')" hint="Updated automatically when stock is received"><input :id="id" v-model="form.cost_price" type="number" step="0.01" min="0" inputmode="decimal" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Promotional price" :error="err('promo_price')"><input :id="id" v-model="form.promo_price" type="number" step="0.01" min="0" inputmode="decimal" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Minimum approved price" :error="err('min_selling_price')" hint="Lower prices need a manager"><input :id="id" v-model="form.min_selling_price" type="number" step="0.01" min="0" inputmode="decimal" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Fitting fee" :error="err('fitting_fee')"><input :id="id" v-model="form.fitting_fee" type="number" step="0.01" min="0" inputmode="decimal" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Balancing fee" :error="err('balancing_fee')"><input :id="id" v-model="form.balancing_fee" type="number" step="0.01" min="0" inputmode="decimal" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Alignment fee" :error="err('alignment_fee')"><input :id="id" v-model="form.alignment_fee" type="number" step="0.01" min="0" inputmode="decimal" class="field" /></FormField>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-head"><h2 class="panel-title">Stock control</h2></div>
                    <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                        <FormField v-slot="{ id }" label="Minimum stock level" :error="err('min_stock_level')" hint="Low-stock alert at or below this"><input :id="id" v-model.number="form.min_stock_level" type="number" min="0" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Reorder quantity" :error="err('reorder_qty')"><input :id="id" v-model.number="form.reorder_qty" type="number" min="0" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Usual location" :error="err('default_location_id')">
                            <select :id="id" v-model="form.default_location_id" class="field"><option value="">None</option><option v-for="l in lookups.sellableLocations" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                        </FormField>
                        <FormField v-slot="{ id }" label="Usual supplier" :error="err('default_supplier_id')">
                            <select :id="id" v-model="form.default_supplier_id" class="field"><option value="">None</option><option v-for="s in lookups.suppliers" :key="s.id" :value="s.id">{{ s.company_name }}</option></select>
                        </FormField>
                        <label class="flex items-center gap-2 text-sm font-medium sm:col-span-2"><input v-model="form.is_public" type="checkbox" class="size-5" /> May appear on the public website availability (when that feature is switched on)</label>
                    </div>
                    <div v-if="!editing && form.condition === 'new' && auth.can('receiving.create')" class="grid gap-4 border-t border-line-soft p-5 sm:grid-cols-2">
                        <p class="text-sm text-muted sm:col-span-2">Optional: record tyres already on the premises as an opening balance. Deliveries from suppliers should be captured under Receive stock instead.</p>
                        <FormField v-slot="{ id }" label="Opening quantity" :error="err('opening_qty')"><input :id="id" v-model.number="form.opening_qty" type="number" min="0" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Opening stock location" :error="err('opening_location_id')">
                            <select :id="id" v-model="form.opening_location_id" class="field"><option value="">Choose</option><option v-for="l in lookups.activeLocations" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                        </FormField>
                    </div>
                </section>

                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary" :disabled="submit.busy.value">{{ editing ? 'Save changes' : 'Add tyre' }}</button>
                    <RouterLink :to="editing ? `/inventory/${route.params.id}` : '/inventory'" class="btn btn-secondary">Cancel</RouterLink>
                </div>
            </form>
        </AsyncState>
    </div>
</template>
