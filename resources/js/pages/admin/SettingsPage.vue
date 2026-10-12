<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Plus, Upload, Trash2, Star } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import FormField from '@/components/ui/FormField.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import { del, get, post, put, type ApiError } from '@/api/http';
import { useAuth } from '@/stores/auth';
import { useLookups } from '@/stores/lookups';
import { useUi } from '@/stores/ui';
import { useSubmit } from '@/composables/useForm';

const auth = useAuth();
const lookups = useLookups();
const ui = useUi();
const sections = computed(() =>
    [
        { key: 'business', label: 'Business and branding', can: 'settings.manage' },
        { key: 'tax', label: 'Tax and sales', can: 'settings.manage' },
        { key: 'inventory', label: 'Stock rules', can: 'settings.manage' },
        { key: 'brands', label: 'Brands and models', can: 'brands.manage' },
        { key: 'locations', label: 'Storage locations', can: 'locations.manage' },
        { key: 'notifications', label: 'Notifications', can: 'settings.manage' },
        { key: 'public', label: 'Public website API', can: 'settings.manage' },
    ].filter((s) => auth.can(s.can)),
);
const active = ref(sections.value[0]?.key ?? 'brands');

// ---- settings
const s = reactive<Record<string, any>>({});
const loading = ref(auth.can('settings.manage'));
const loadError = ref<string | null>(null);
const submit = useSubmit();
async function loadSettings() {
    if (!auth.can('settings.manage')) return;
    try {
        Object.assign(s, (await get('/settings')).data);
        s.grades_text = (s.used_tyre_grades ?? []).join(', ');
    } catch (e) {
        loadError.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
async function saveSettings(keys: string[]) {
    const body: Record<string, unknown> = {};
    for (const k of keys) body[k] = s[k];
    if (keys.includes('used_tyre_grades')) body.used_tyre_grades = String(s.grades_text).split(',').map((g) => g.trim()).filter(Boolean);
    const res = await submit.run(() => put('/settings', body), 'Settings saved.');
    if (res) {
        Object.assign(s, res.data);
        s.grades_text = (s.used_tyre_grades ?? []).join(', ');
        lookups.load(true);
    }
}
async function uploadLogo(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const data = new FormData();
    data.append('logo', file);
    try {
        Object.assign(s, (await post('/settings/logo', data)).data);
        ui.toast('Logo updated.');
        lookups.load(true);
    } catch (err) {
        ui.toast((err as ApiError).field('logo') ?? (err as ApiError).message, 'error');
    }
}
async function removeLogo() {
    Object.assign(s, (await del('/settings/logo')).data);
    lookups.load(true);
}

// ---- brands
const brands = ref<any[]>([]);
const newBrand = ref('');
const newModel = reactive<Record<number, string>>({});
async function loadBrands() {
    if (auth.can('brands.manage')) brands.value = (await get('/brands')).data;
}
async function addBrand() {
    if (await submit.run(() => post('/brands', { name: newBrand.value }), 'Brand added.')) {
        newBrand.value = '';
        loadBrands();
        lookups.load(true);
    }
}
async function updateBrand(b: any, body: object, msg: string) {
    if (await submit.run(() => put(`/brands/${b.id}`, body), msg)) {
        loadBrands();
        lookups.load(true);
    }
}
async function addModel(b: any) {
    if (await submit.run(() => post(`/brands/${b.id}/models`, { name: newModel[b.id] }), 'Model added.')) {
        newModel[b.id] = '';
        loadBrands();
        lookups.load(true);
    }
}

// ---- locations
const locations = ref<any[]>([]);
const loc = reactive({ code: '', name: '', type: 'rack', is_sellable: true });
async function loadLocations() {
    if (auth.can('locations.manage')) locations.value = (await get('/locations')).data;
}
async function addLocation() {
    if (await submit.run(() => post('/locations', loc), 'Location added.')) {
        Object.assign(loc, { code: '', name: '', type: 'rack', is_sellable: true });
        loadLocations();
        lookups.load(true);
    }
}
async function updateLocation(l: any, body: object) {
    if (await submit.run(() => put(`/locations/${l.id}`, body), 'Location updated.')) {
        loadLocations();
        lookups.load(true);
    }
}

onMounted(() => Promise.all([loadSettings(), loadBrands(), loadLocations()]));
</script>

<template>
    <div>
        <PageHeader title="Settings" subtitle="Business details, tax, stock rules, brands, locations and integrations." />
        <div class="grid gap-5 lg:grid-cols-[220px_minmax(0,1fr)]">
            <nav class="no-print flex gap-1 overflow-x-auto lg:flex-col" aria-label="Settings sections">
                <button v-for="sec in sections" :key="sec.key" type="button" class="rounded-[6px] px-3 py-2.5 text-left text-sm font-semibold whitespace-nowrap" :class="active === sec.key ? 'bg-asphalt text-white' : 'text-muted hover:bg-panel'" @click="active = sec.key">{{ sec.label }}</button>
            </nav>

            <AsyncState :loading="loading" :error="loadError">
                <!-- Business -->
                <section v-if="active === 'business'" class="panel p-5">
                    <h2 class="panel-title mb-4">Business and branding</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField v-slot="{ id }" label="Business name" :error="submit.fieldError('business_name')"><input :id="id" v-model="s.business_name" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Telephone"><input :id="id" v-model="s.business_phone" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="WhatsApp number" hint="International format, e.g. +27764544272" :error="submit.fieldError('whatsapp_number')"><input :id="id" v-model="s.whatsapp_number" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="E-mail" :error="submit.fieldError('business_email')"><input :id="id" v-model="s.business_email" type="email" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Physical address"><textarea :id="id" v-model="s.business_address" rows="3" class="field" /></FormField>
                        <div class="space-y-4">
                            <FormField v-slot="{ id }" label="Website"><input :id="id" v-model="s.website_url" class="field" /></FormField>
                            <FormField v-slot="{ id }" label="Brand colour" :error="submit.fieldError('brand_color')"><div class="flex gap-2"><input :id="id" v-model="s.brand_color" type="color" class="h-11 w-14 rounded border border-line" /><input v-model="s.brand_color" class="field" aria-label="Brand colour hex" /></div></FormField>
                        </div>
                        <FormField v-slot="{ id }" label="Currency"><select :id="id" v-model="s.currency" class="field"><option value="ZAR">South African Rand (ZAR)</option></select></FormField>
                        <FormField v-slot="{ id }" label="Timezone"><select :id="id" v-model="s.timezone" class="field"><option value="Africa/Johannesburg">Africa/Johannesburg</option></select></FormField>
                    </div>
                    <div class="mt-5 flex flex-wrap items-center gap-4 border-t border-line-soft pt-5">
                        <img :src="s.logo_url" alt="Current logo" class="size-20 rounded-full bg-black object-contain" />
                        <p class="text-sm text-muted">{{ s.logo_is_default ? 'Using the Super Wheels and Tyres logo. Upload a file only to replace it (square PNG/JPG/WebP, max 2 MB).' : 'Using an uploaded logo.' }}</p>
                        <label class="btn btn-secondary cursor-pointer"><Upload class="size-4" /> {{ s.logo_is_default ? 'Replace logo' : 'Upload another' }}<input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="uploadLogo" /></label>
                        <button v-if="!s.logo_is_default" type="button" class="btn btn-ghost" @click="removeLogo"><Trash2 class="size-4" /> Use the standard logo</button>
                    </div>
                    <button type="button" class="btn btn-primary mt-5" :disabled="submit.busy.value" @click="saveSettings(['business_name', 'business_phone', 'whatsapp_number', 'business_email', 'business_address', 'website_url', 'brand_color', 'currency', 'timezone'])">Save business details</button>
                </section>

                <!-- Tax -->
                <section v-if="active === 'tax'" class="panel p-5">
                    <h2 class="panel-title mb-1">Tax and sales</h2>
                    <p class="mb-4 text-sm text-muted">VAT is only calculated when the business is registered for VAT. Existing sales keep the VAT they were recorded with.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex items-center gap-2 font-medium sm:col-span-2"><input v-model="s.vat_registered" type="checkbox" class="size-5" /> The business is VAT registered</label>
                        <template v-if="s.vat_registered">
                            <FormField v-slot="{ id }" label="VAT number"><input :id="id" v-model="s.vat_number" class="field" /></FormField>
                            <FormField v-slot="{ id }" label="VAT rate (%)" :error="submit.fieldError('vat_rate')"><input :id="id" v-model="s.vat_rate" type="number" step="0.01" class="field" /></FormField>
                            <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="s.prices_include_vat" type="checkbox" class="size-5" /> Selling prices already include VAT</label>
                        </template>
                        <FormField v-slot="{ id }" label="Reservation period (hours)" :error="submit.fieldError('reservation_hours')"><input :id="id" v-model.number="s.reservation_hours" type="number" min="1" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Quotation valid for (days)"><input :id="id" v-model.number="s.quotation_valid_days" type="number" min="1" class="field" /></FormField>
                    </div>
                    <button type="button" class="btn btn-primary mt-5" :disabled="submit.busy.value" @click="saveSettings(['vat_registered', 'vat_number', 'vat_rate', 'prices_include_vat', 'reservation_hours', 'quotation_valid_days'])">Save</button>
                </section>

                <!-- Inventory -->
                <section v-if="active === 'inventory'" class="panel p-5">
                    <h2 class="panel-title mb-4">Stock rules</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField v-slot="{ id }" label="Default minimum stock for new tyres"><input :id="id" v-model.number="s.default_min_stock" type="number" min="0" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Slow-moving after (days)"><input :id="id" v-model.number="s.slow_moving_days" type="number" min="7" class="field" /></FormField>
                        <FormField v-slot="{ id }" label="Used tyre grades" hint="Comma separated, e.g. A, B, C. Internal classification only."><input :id="id" v-model="s.grades_text" class="field" /></FormField>
                        <div />
                        <fieldset class="sm:col-span-2">
                            <legend class="field-label">While a stocktake is being counted</legend>
                            <label class="mb-2 flex items-start gap-2 text-sm"><input v-model="s.stocktake_lock_mode" type="radio" value="block" class="mt-1" /> <span><strong>Pause stock transactions</strong> on the products and locations being counted (recommended). Sales and receipts of that stock are refused until the count is submitted.</span></label>
                            <label class="flex items-start gap-2 text-sm"><input v-model="s.stocktake_lock_mode" type="radio" value="track" class="mt-1" /> <span><strong>Allow and track</strong>. Business continues; every count stores the system quantity at that moment and reviewers see the movements made during the count.</span></label>
                        </fieldset>
                    </div>
                    <button type="button" class="btn btn-primary mt-5" :disabled="submit.busy.value" @click="saveSettings(['default_min_stock', 'slow_moving_days', 'used_tyre_grades', 'stocktake_lock_mode'])">Save</button>
                </section>

                <!-- Brands -->
                <section v-if="active === 'brands'" class="panel">
                    <div class="panel-head"><h2 class="panel-title">Brands and models</h2></div>
                    <form class="flex gap-2 border-b border-line-soft p-4" @submit.prevent="addBrand"><input v-model="newBrand" class="field" placeholder="New brand name" aria-label="New brand name" /><button class="btn btn-primary" :disabled="!newBrand"><Plus class="size-4" /> Add brand</button></form>
                    <ul class="divide-y divide-line-soft">
                        <li v-for="b in brands" :key="b.id" class="p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-display text-lg font-semibold" :class="b.archived_at ? 'text-faint line-through' : ''">{{ b.name }}</span>
                                <Star v-if="b.is_preferred" class="size-4 fill-current text-warn" aria-label="Preferred brand" />
                                <span class="text-sm text-muted">{{ b.products_count }} tyre records</span>
                                <div class="ml-auto flex gap-1">
                                    <button type="button" class="btn btn-ghost btn-sm" @click="updateBrand(b, { is_preferred: !b.is_preferred }, 'Brand updated.')">{{ b.is_preferred ? 'Not preferred' : 'Mark preferred' }}</button>
                                    <button v-if="!b.archived_at" type="button" class="btn btn-ghost btn-sm" @click="updateBrand(b, { is_active: !b.is_active }, b.is_active ? 'Brand deactivated.' : 'Brand activated.')">{{ b.is_active ? 'Deactivate' : 'Activate' }}</button>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="updateBrand(b, { archived: !b.archived_at, is_active: !!b.archived_at }, b.archived_at ? 'Brand restored.' : 'Brand archived.')">{{ b.archived_at ? 'Restore' : 'Archive' }}</button>
                                </div>
                            </div>
                            <p class="mt-1 text-sm text-muted">{{ b.models.map((m: any) => m.name).join(', ') || 'No models yet' }}</p>
                            <form v-if="!b.archived_at" class="mt-2 flex max-w-md gap-2" @submit.prevent="addModel(b)"><input v-model="newModel[b.id]" class="field !min-h-9 !py-1" placeholder="Add model" :aria-label="`New ${b.name} model`" /><button class="btn btn-secondary btn-sm" :disabled="!newModel[b.id]">Add</button></form>
                        </li>
                    </ul>
                </section>

                <!-- Locations -->
                <section v-if="active === 'locations'" class="panel">
                    <div class="panel-head"><h2 class="panel-title">Storage locations</h2></div>
                    <form class="grid gap-2 border-b border-line-soft p-4 sm:grid-cols-[120px_1fr_150px_auto_auto]" @submit.prevent="addLocation">
                        <input v-model="loc.code" class="field" placeholder="Code" aria-label="Code" required />
                        <input v-model="loc.name" class="field" placeholder="Name, e.g. Rack D" aria-label="Name" required />
                        <select v-model="loc.type" class="field" aria-label="Type"><option value="store">Store</option><option value="rack">Rack</option><option value="workshop">Workshop</option><option value="used">Used tyres</option><option value="quarantine">Quarantine</option><option value="damaged">Damaged</option></select>
                        <label class="flex items-center gap-2 text-sm"><input v-model="loc.is_sellable" type="checkbox" /> Sellable</label>
                        <button class="btn btn-primary"><Plus class="size-4" /> Add</button>
                    </form>
                    <p v-if="submit.fieldError('code')" class="field-error px-4 pt-2">{{ submit.fieldError('code') }}</p>
                    <table class="data-table">
                        <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Sellable</th><th class="num">Tyres</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="l in locations" :key="l.id">
                                <td class="font-semibold">{{ l.code }}</td><td>{{ l.name }}</td><td>{{ l.type }}</td>
                                <td>{{ l.is_sellable ? 'Yes' : 'No – held stock' }}</td><td class="num">{{ l.qty }}</td>
                                <td>{{ l.is_active ? 'Active' : 'Inactive' }}</td>
                                <td><button type="button" class="btn btn-ghost btn-sm" @click="updateLocation(l, { is_active: !l.is_active })">{{ l.is_active ? 'Deactivate' : 'Activate' }}</button></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="p-4 text-sm text-muted">Stock in non-sellable locations (quarantine, damaged) is counted as physical stock but is never available to sell. Locations holding stock cannot be deactivated.</p>
                </section>

                <!-- Notifications -->
                <section v-if="active === 'notifications'" class="panel p-5">
                    <h2 class="panel-title mb-1">Notifications</h2>
                    <p class="mb-4 text-sm text-muted">Alerts always appear in the notification centre. E-mail copies are optional and need SMTP settings in the server .env file{{ s.mail_configured ? '' : ' (not configured yet)' }}.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="s.notify_low_stock" type="checkbox" class="size-5" /> Low-stock and out-of-stock alerts</label>
                        <FormField v-slot="{ id }" label="Warn before reservations expire (hours)"><input :id="id" v-model.number="s.notify_reservation_expiry_hours" type="number" min="1" class="field" /></FormField>
                        <div />
                        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="s.notify_email_enabled" type="checkbox" class="size-5" /> Also send stock alerts by e-mail</label>
                        <div class="sm:col-span-2"><FormField v-slot="{ id }" label="E-mail recipients" hint="Comma separated" :error="submit.fieldError('notify_emails')"><input :id="id" v-model="s.notify_emails" class="field" /></FormField></div>
                    </div>
                    <button type="button" class="btn btn-primary mt-5" :disabled="submit.busy.value" @click="saveSettings(['notify_low_stock', 'notify_reservation_expiry_hours', 'notify_email_enabled', 'notify_emails'])">Save</button>
                </section>

                <!-- Public API -->
                <section v-if="active === 'public'" class="panel p-5">
                    <h2 class="panel-title mb-1">Public website API</h2>
                    <p class="mb-4 text-sm text-muted">Lets the public website show tyre availability. It only ever shares brand, model, size, condition, availability and (optionally) the selling price – never costs, suppliers, employees or history. Off until you switch it on.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex items-center gap-2 font-medium sm:col-span-2"><input v-model="s.public_api_enabled" type="checkbox" class="size-5" /> Publish tyre availability</label>
                        <fieldset class="sm:col-span-2">
                            <legend class="field-label">What to show</legend>
                            <label class="block text-sm"><input v-model="s.public_api_visibility" type="radio" value="status" /> Availability status only – “in stock”, “limited” (recommended)</label>
                            <label class="block text-sm"><input v-model="s.public_api_visibility" type="radio" value="quantity" /> Exact available quantities</label>
                            <label class="block text-sm"><input v-model="s.public_api_visibility" type="radio" value="hidden" /> Hide availability</label>
                        </fieldset>
                        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="s.public_api_show_prices" type="checkbox" class="size-5" /> Include selling prices</label>
                        <div class="sm:col-span-2"><FormField v-slot="{ id }" label="Allowed website origins (CORS)" hint="Comma separated, e.g. https://superwheeltyres.co.za"><input :id="id" v-model="s.public_api_allowed_origins" class="field" /></FormField></div>
                        <p class="text-sm sm:col-span-2">Endpoint: <code class="rounded bg-surface px-1.5 py-0.5">{{ s.public_api_url }}?width=205&amp;profile=55&amp;rim=16</code></p>
                    </div>
                    <button type="button" class="btn btn-primary mt-5" :disabled="submit.busy.value" @click="saveSettings(['public_api_enabled', 'public_api_visibility', 'public_api_show_prices', 'public_api_allowed_origins'])">Save</button>
                </section>
            </AsyncState>
        </div>
    </div>
</template>
