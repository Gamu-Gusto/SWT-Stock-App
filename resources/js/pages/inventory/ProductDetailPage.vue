<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { Pencil, Archive, RotateCcw, CalendarClock, ShoppingCart, FileText, ArrowLeftRight, SlidersHorizontal, Plus, ImagePlus, Lock, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import FormField from '@/components/ui/FormField.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import { del, get, post, type ApiError } from '@/api/http';
import { useAuth } from '@/stores/auth';
import { useLookups } from '@/stores/lookups';
import { useUi } from '@/stores/ui';
import { useSubmit } from '@/composables/useForm';
import { useList } from '@/composables/useList';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';
import type { Media, Product, TyreUnit } from '@/types';

type Detail = Product & {
    images: Media[];
    default_location: string | null;
    default_supplier: string | null;
    active_reservations: { id: number; reservation_no: string; customer_name: string; quantity: number; expires_at: string }[];
    locked_by: { id: number; stocktake_no: string }[];
    cost_layers?: { id: number; received_at: string; source: string; qty_remaining: number; unit_cost: string }[];
    stock_value?: string;
    valuation_method?: 'fifo' | 'unit';
};

const route = useRoute();
const auth = useAuth();
const lookups = useLookups();
const ui = useUi();
const product = ref<Detail | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const units = ref<TyreUnit[]>([]);
const unitFilter = ref('in_stock');

async function load() {
    loading.value = true;
    error.value = null;
    try {
        product.value = (await get<{ data: Detail }>(`/products/${route.params.id}`)).data;
        if (product.value.tracking === 'unit') await loadUnits();
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
async function loadUnits() {
    units.value = (await get<{ data: TyreUnit[] }>(`/products/${route.params.id}/units`, { status: unitFilter.value })).data;
}
onMounted(load);

const movements = auth.can('movements.view') ? useList(() => `/products/${route.params.id}/movements`, { per_page: 15 }) : null;

// Archive / restore
const action = useSubmit();
async function archive(restore = false) {
    if (!restore && !confirm('Archive this tyre? It will be hidden from search and sales. Archiving is only possible with no stock.')) return;
    const res = await action.run(() => post(`/products/${route.params.id}/${restore ? 'restore' : 'archive'}`), restore ? 'Tyre restored.' : 'Tyre archived.');
    if (res) load();
}

// Add used tyre unit
const unitModal = ref(false);
const unitForm = reactive({ location_id: '' as number | '', tread_depth_mm: '', grade: '', dot_code: '', defects: '', inspection_notes: '', selling_price: '', cost_price: '', status: 'pending_inspection' });
const unitSubmit = useSubmit();
function openUnitModal() {
    Object.assign(unitForm, { location_id: product.value?.default_location_id ?? '', tread_depth_mm: '', grade: '', dot_code: '', defects: '', inspection_notes: '', selling_price: product.value?.selling_price ?? '', cost_price: '', status: auth.can('used.inspect') ? 'available' : 'pending_inspection' });
    unitModal.value = true;
}
async function saveUnit() {
    const body = Object.fromEntries(Object.entries(unitForm).map(([k, v]) => [k, v === '' ? null : v]));
    const res = await unitSubmit.run(() => post(`/products/${route.params.id}/units`, body), 'Used tyre registered.');
    if (res) {
        unitModal.value = false;
        load();
    }
}

// Photos
const uploading = ref(false);
async function upload(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const data = new FormData();
    data.append('image', file);
    uploading.value = true;
    try {
        await post(`/products/${route.params.id}/images`, data);
        ui.toast('Photo uploaded.');
        load();
    } catch (err) {
        ui.toast((err as ApiError).field('image') ?? (err as ApiError).message, 'error');
    } finally {
        uploading.value = false;
        (e.target as HTMLInputElement).value = '';
    }
}
async function removeImage(m: Media) {
    if (!confirm('Delete this photo?')) return;
    await del(`/media/${m.id}`);
    load();
}

const sellable = computed(() => (product.value?.locations ?? []).filter((l) => l.is_sellable));
const held = computed(() => (product.value?.locations ?? []).filter((l) => !l.is_sellable));
</script>

<template>
    <AsyncState :loading="loading" :error="error" @retry="load">
        <div v-if="product">
            <PageHeader :title="`${product.brand?.name ?? ''} ${product.model}`" back="/inventory">
                <template #meta>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="size-chip size-chip-lg">{{ product.spec?.size }}</span>
                        <span v-if="product.spec?.load_index" class="font-display text-xl font-semibold">{{ product.spec.load_index }}{{ product.spec.speed_rating }}</span>
                        <span class="rounded-full px-2.5 py-0.5 text-sm font-semibold" :class="product.condition === 'used' ? 'bg-warn-soft text-warn' : 'bg-line-soft text-ink'">{{ product.condition === 'used' ? 'Used' : 'New' }}</span>
                        <StatusBadge :status="product.status === 'archived' ? 'archived' : product.stock_status" />
                        <span class="text-sm text-muted">{{ product.sku }}</span>
                    </div>
                </template>
                <template #actions>
                    <RouterLink v-if="auth.can('sales.create') && product.qty_available > 0" :to="`/sales/new?product_id=${product.id}`" class="btn btn-primary"><ShoppingCart class="size-4" /> Sell</RouterLink>
                    <RouterLink v-if="auth.can('reservations.manage') && product.qty_available > 0" :to="`/reservations/new?product_id=${product.id}`" class="btn btn-secondary"><CalendarClock class="size-4" /> Reserve</RouterLink>
                    <RouterLink v-if="auth.can('quotations.manage')" :to="`/quotations/new?product_id=${product.id}`" class="btn btn-secondary"><FileText class="size-4" /> Quote</RouterLink>
                    <RouterLink v-if="auth.can('inventory.manage')" :to="`/inventory/${product.id}/edit`" class="btn btn-secondary"><Pencil class="size-4" /> Edit</RouterLink>
                </template>
            </PageHeader>

            <div v-if="product.locked_by.length" class="mb-4 flex items-center gap-2 rounded-[10px] border border-info/30 bg-info-soft p-3 text-sm text-info">
                <Lock class="size-4" /> Being counted in stocktake {{ product.locked_by.map((s) => s.stocktake_no).join(', ') }}.
                <span v-if="lookups.settings?.stocktake_lock_mode === 'block'">Sales, receipts and transfers of this tyre at the counted location are paused until the count is submitted.</span>
            </div>

            <!-- Quantities -->
            <div class="mb-5 grid grid-cols-2 overflow-hidden rounded-[10px] border border-line bg-panel sm:grid-cols-4">
                <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Available to sell</p><p class="font-display text-[40px] leading-tight font-bold tabular-nums">{{ product.qty_available }}</p></div>
                <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Physically in stock</p><p class="font-display text-[40px] leading-tight font-bold tabular-nums">{{ product.qty_physical }}</p><p v-if="product.qty_unsellable" class="text-[13px] text-muted">{{ product.qty_unsellable }} not sellable (inspection / damaged)</p></div>
                <div class="border-r border-line-soft p-4"><p class="text-sm text-muted">Reserved</p><p class="font-display text-[40px] leading-tight font-bold tabular-nums">{{ product.qty_reserved }}</p></div>
                <div class="p-4">
                    <p class="text-sm text-muted">Price per tyre</p>
                    <p class="font-display text-[30px] leading-tight font-bold tabular-nums">{{ product.tracking === 'unit' && product.unit_price_range ? formatMoney(product.unit_price_range[0]) : formatMoney(product.effective_price) }}</p>
                    <p v-if="product.promo_price && product.effective_price !== product.selling_price" class="text-[13px] text-muted">Promotion · normally {{ formatMoney(product.selling_price) }}</p>
                    <p v-if="product.tracking === 'unit'" class="text-[13px] text-muted">Each used tyre has its own price</p>
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div class="space-y-5">
                    <!-- Used tyre units -->
                    <section v-if="product.tracking === 'unit'" class="panel">
                        <div class="panel-head">
                            <h2 class="panel-title">Individual used tyres</h2>
                            <div class="flex gap-2">
                                <select v-model="unitFilter" class="field !min-h-9 !py-1 text-sm" aria-label="Filter tyres" @change="loadUnits">
                                    <option value="in_stock">In stock</option><option value="available">Available</option><option value="pending_inspection">Awaiting inspection</option><option value="sold">Sold</option><option value="all">All</option>
                                </select>
                                <button v-if="auth.can('inventory.manage')" type="button" class="btn btn-dark btn-sm" @click="openUnitModal"><Plus class="size-4" /> Add used tyre</button>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead><tr><th>Tyre code</th><th>Tread</th><th>Grade</th><th>DOT</th><th>Location</th><th class="num">Price</th><th>Status</th></tr></thead>
                                <tbody>
                                    <tr v-for="u in units" :key="u.id">
                                        <td><RouterLink :to="`/units/${u.id}`" class="font-semibold hover:underline">{{ u.unit_code }}</RouterLink></td>
                                        <td>{{ u.tread_depth_mm ? `${u.tread_depth_mm} mm` : '–' }}</td>
                                        <td>{{ u.grade ?? '–' }}</td>
                                        <td>{{ u.dot_code ?? '–' }}</td>
                                        <td>{{ u.location?.name ?? '–' }}</td>
                                        <td class="num font-semibold">{{ formatMoney(u.selling_price) }}</td>
                                        <td><StatusBadge :status="u.status" /></td>
                                    </tr>
                                    <tr v-if="!units.length"><td colspan="7" class="py-8 text-center text-muted">No used tyres in this view.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="border-t border-line-soft px-5 py-3 text-[13px] text-muted">Grades are internal stock classifications. They do not replace a safety inspection, and rejected tyres can never be sold.</p>
                    </section>

                    <!-- Stock by location -->
                    <section class="panel">
                        <div class="panel-head">
                            <h2 class="panel-title">Where it is</h2>
                            <div class="flex gap-2">
                                <RouterLink v-if="auth.can('transfers.create')" :to="`/movements/transfers?product_id=${product.id}`" class="btn btn-secondary btn-sm"><ArrowLeftRight class="size-4" /> Transfer</RouterLink>
                                <RouterLink v-if="auth.can('adjustments.request')" :to="`/movements/adjustments?product_id=${product.id}`" class="btn btn-secondary btn-sm"><SlidersHorizontal class="size-4" /> Adjust</RouterLink>
                            </div>
                        </div>
                        <ul class="divide-y divide-line-soft">
                            <li v-for="l in sellable" :key="l.location_id" class="flex items-center justify-between px-5 py-3"><span class="font-medium">{{ l.name }}</span><span class="qty">{{ l.qty }}</span></li>
                            <li v-for="l in held" :key="l.location_id" class="flex items-center justify-between px-5 py-3 text-muted"><span>{{ l.name }} <span class="text-[12.5px]">(not sellable)</span></span><span class="qty">{{ l.qty }}</span></li>
                            <li v-if="!product.locations?.length" class="px-5 py-6 text-center text-sm text-muted">No stock on hand.</li>
                        </ul>
                    </section>

                    <!-- Movement history -->
                    <section v-if="movements" class="panel overflow-hidden">
                        <div class="panel-head"><h2 class="panel-title">Stock movement history</h2></div>
                        <AsyncState :loading="movements.loading.value && !movements.items.value.length" :error="movements.error.value" :empty="!movements.items.value.length" empty-title="No movements yet">
                            <div class="overflow-x-auto">
                                <table class="data-table">
                                    <thead><tr><th>Date</th><th>Type</th><th>Location</th><th class="num">Before</th><th class="num">Change</th><th class="num">After</th><th>Reference</th><th>By</th></tr></thead>
                                    <tbody>
                                        <tr v-for="m in movements.items.value" :key="m.id">
                                            <td class="whitespace-nowrap">{{ formatDateTime(m.created_at) }}</td>
                                            <td>{{ m.type_label }}<div v-if="m.unit_code" class="text-[12px] text-muted">{{ m.unit_code }}</div></td>
                                            <td>{{ m.location }}</td>
                                            <td class="num">{{ m.previous_qty }}</td>
                                            <td class="num font-semibold" :class="m.quantity_change > 0 ? 'text-ok' : 'text-bad'">{{ m.quantity_change > 0 ? '+' : '' }}{{ m.quantity_change }}</td>
                                            <td class="num">{{ m.resulting_qty }}</td>
                                            <td class="text-[13px]">{{ m.reference_no }}<div v-if="m.reason" class="max-w-[220px] truncate text-muted" :title="m.reason">{{ m.reason }}</div></td>
                                            <td class="text-[13px]">{{ m.user }}<div v-if="m.approved_by" class="text-muted">approved {{ m.approved_by }}</div></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <PaginationBar :page="movements.page.value" :last-page="movements.lastPage.value" @change="movements.load" />
                        </AsyncState>
                    </section>
                </div>

                <aside class="space-y-5">
                    <section class="panel">
                        <div class="panel-head"><h2 class="panel-title">Details</h2></div>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 p-5 text-sm">
                            <dt class="text-muted">Type</dt><dd>{{ product.spec?.tyre_type ? lookups.tyreTypes[product.spec.tyre_type] : '–' }}{{ product.spec?.run_flat ? ' · run flat' : '' }}</dd>
                            <dt class="text-muted">Minimum stock</dt><dd>{{ product.min_stock_level }}</dd>
                            <dt class="text-muted">Reorder qty</dt><dd>{{ product.reorder_qty }}</dd>
                            <dt class="text-muted">Usual location</dt><dd>{{ product.default_location ?? '–' }}</dd>
                            <dt v-if="product.default_supplier" class="text-muted">Usual supplier</dt><dd v-if="product.default_supplier">{{ product.default_supplier }}</dd>
                            <dt class="text-muted">Last received</dt><dd>{{ formatDate(product.last_received_at) }}</dd>
                            <dt class="text-muted">Last sold</dt><dd>{{ formatDate(product.last_sold_at) }}</dd>
                            <dt v-if="product.fitting_fee" class="text-muted">Fitting</dt><dd v-if="product.fitting_fee">{{ formatMoney(product.fitting_fee) }}</dd>
                            <dt v-if="product.balancing_fee" class="text-muted">Balancing</dt><dd v-if="product.balancing_fee">{{ formatMoney(product.balancing_fee) }}</dd>
                            <dt v-if="product.alignment_fee" class="text-muted">Alignment</dt><dd v-if="product.alignment_fee">{{ formatMoney(product.alignment_fee) }}</dd>
                            <dt v-if="product.min_selling_price" class="text-muted">Minimum price</dt><dd v-if="product.min_selling_price">{{ formatMoney(product.min_selling_price) }}</dd>
                        </dl>
                        <p v-if="product.description" class="border-t border-line-soft px-5 py-3 text-sm">{{ product.description }}</p>
                    </section>

                    <section v-if="product.cost_layers" class="panel">
                        <div class="panel-head"><h2 class="panel-title">{{ product.valuation_method === 'unit' ? 'Cost of tyres on hand' : 'Cost (FIFO)' }}</h2><span class="font-semibold">{{ formatMoney(product.stock_value) }}</span></div>
                        <p v-if="product.valuation_method === 'unit'" class="px-5 py-3 text-sm text-muted">Each used tyre keeps its own cost price; this is the total for the tyres physically here.</p>
                        <p v-else class="px-5 pt-3 text-sm text-muted">Standard cost {{ formatMoney(product.cost_price) }}. Oldest stock is costed out first.</p>
                        <ul class="divide-y divide-line-soft text-sm">
                            <li v-for="l in product.cost_layers" :key="l.id" class="flex justify-between px-5 py-2"><span>{{ formatDate(l.received_at) }} · {{ l.qty_remaining }} left</span><span>{{ formatMoney(l.unit_cost) }}</span></li>
                        </ul>
                    </section>

                    <section v-if="product.active_reservations.length" class="panel">
                        <div class="panel-head"><h2 class="panel-title">Reserved for</h2></div>
                        <ul class="divide-y divide-line-soft text-sm">
                            <li v-for="r in product.active_reservations" :key="r.id" class="px-5 py-3">
                                <RouterLink :to="`/reservations/${r.id}`" class="font-semibold hover:underline">{{ r.customer_name }}</RouterLink> – {{ r.quantity }}
                                <div class="text-muted">{{ r.reservation_no }}, until {{ formatDateTime(r.expires_at) }}</div>
                            </li>
                        </ul>
                    </section>

                    <section class="panel">
                        <div class="panel-head">
                            <h2 class="panel-title">Photos</h2>
                            <label v-if="auth.can('inventory.manage')" class="btn btn-secondary btn-sm cursor-pointer"><ImagePlus class="size-4" /> {{ uploading ? 'Uploading…' : 'Add' }}<input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" capture="environment" @change="upload" /></label>
                        </div>
                        <div v-if="product.images.length" class="grid grid-cols-2 gap-2 p-4">
                            <div v-for="m in product.images" :key="m.id" class="group relative">
                                <a :href="m.url" target="_blank" rel="noopener"><img :src="m.url" :alt="m.name" class="aspect-square w-full rounded-[6px] object-cover" loading="lazy" /></a>
                                <button v-if="auth.can('inventory.manage')" type="button" class="absolute top-1 right-1 rounded bg-white/90 p-1" aria-label="Delete photo" @click="removeImage(m)"><Trash2 class="size-4 text-bad" /></button>
                            </div>
                        </div>
                        <p v-else class="p-5 text-sm text-muted">No photos yet. JPG, PNG or WebP up to 5 MB.</p>
                    </section>

                    <div v-if="auth.can('inventory.archive')" class="flex justify-end">
                        <button v-if="product.status === 'active'" type="button" class="btn btn-ghost text-bad" :disabled="action.busy.value" @click="archive()"><Archive class="size-4" /> Archive tyre</button>
                        <button v-else type="button" class="btn btn-secondary" @click="archive(true)"><RotateCcw class="size-4" /> Restore</button>
                    </div>
                </aside>
            </div>

            <ModalDialog :open="unitModal" title="Add used tyre" @close="unitModal = false">
                <form id="unit-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveUnit">
                    <FormField v-slot="{ id }" label="Location" required :error="unitSubmit.fieldError('location_id')">
                        <select :id="id" v-model="unitForm.location_id" class="field" required><option value="" disabled>Choose</option><option v-for="l in lookups.activeLocations" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                    </FormField>
                    <FormField v-slot="{ id }" label="Selling price" required :error="unitSubmit.fieldError('selling_price')"><input :id="id" v-model="unitForm.selling_price" type="number" step="0.01" min="0" class="field" required /></FormField>
                    <FormField v-slot="{ id }" label="Tread depth (mm)" :error="unitSubmit.fieldError('tread_depth_mm')"><input :id="id" v-model="unitForm.tread_depth_mm" type="number" step="0.1" min="0" class="field" /></FormField>
                    <FormField v-slot="{ id }" label="Grade" :error="unitSubmit.fieldError('grade')">
                        <select :id="id" v-model="unitForm.grade" class="field" :disabled="!auth.can('used.inspect')"><option value="">Not graded</option><option v-for="g in lookups.settings?.used_tyre_grades" :key="g" :value="g">Grade {{ g }}</option></select>
                    </FormField>
                    <FormField v-slot="{ id }" label="DOT code" :error="unitSubmit.fieldError('dot_code')" hint="Week and year, e.g. 2224"><input :id="id" v-model="unitForm.dot_code" class="field" /></FormField>
                    <FormField v-if="auth.can('financials.view')" v-slot="{ id }" label="Cost price" :error="unitSubmit.fieldError('cost_price')"><input :id="id" v-model="unitForm.cost_price" type="number" step="0.01" min="0" class="field" /></FormField>
                    <FormField v-slot="{ id }" label="Inspection result" :error="unitSubmit.fieldError('status')">
                        <select :id="id" v-model="unitForm.status" class="field" :disabled="!auth.can('used.inspect')">
                            <option value="pending_inspection">Not inspected yet</option><option value="available">Passed – available for sale</option><option value="rejected">Rejected – not for sale</option>
                        </select>
                    </FormField>
                    <div class="sm:col-span-2"><FormField v-slot="{ id }" label="Visible defects / notes"><textarea :id="id" v-model="unitForm.defects" rows="2" class="field" /></FormField></div>
                </form>
                <template #footer>
                    <button type="button" class="btn btn-secondary" @click="unitModal = false">Cancel</button>
                    <button type="submit" form="unit-form" class="btn btn-primary" :disabled="unitSubmit.busy.value">Add used tyre</button>
                </template>
            </ModalDialog>
        </div>
    </AsyncState>
</template>
