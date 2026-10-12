<script setup lang="ts">
import { useRoute } from 'vue-router';
import { Plus, Upload } from 'lucide-vue-next';
import { useList } from '@/composables/useList';
import { useLookups } from '@/stores/lookups';
import { useAuth } from '@/stores/auth';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import ProductResults from '@/components/inventory/ProductResults.vue';
import type { Product } from '@/types';

const route = useRoute();
const lookups = useLookups();
const auth = useAuth();

const list = useList<Product>('/products', {
    q: '',
    condition: '',
    brand_id: '',
    location_id: '',
    stock_status: String(route.query.stock_status ?? ''),
    include_archived: '',
    sort: 'size',
    per_page: 50,
});
</script>

<template>
    <div>
        <PageHeader title="Tyre inventory" subtitle="Every tyre record with live physical, reserved and available quantities.">
            <template #actions>
                <RouterLink v-if="auth.can('inventory.import')" to="/import" class="btn btn-secondary"><Upload class="size-4" /> Import</RouterLink>
                <RouterLink v-if="auth.can('inventory.manage')" to="/inventory/new" class="btn btn-primary"><Plus class="size-4" /> Add tyre</RouterLink>
            </template>
        </PageHeader>

        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
            <div class="col-span-2 md:col-span-4 xl:col-span-2">
                <label for="inv-q" class="field-label">Search</label>
                <input id="inv-q" v-model="list.filters.q" type="search" class="field" placeholder="Size, brand, model or SKU" />
            </div>
            <div><label class="field-label" for="inv-cond">Condition</label><select id="inv-cond" v-model="list.filters.condition" class="field"><option value="">All</option><option value="new">New</option><option value="used">Used</option></select></div>
            <div><label class="field-label" for="inv-brand">Brand</label><select id="inv-brand" v-model="list.filters.brand_id" class="field"><option value="">All brands</option><option v-for="b in lookups.brands" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
            <div><label class="field-label" for="inv-status">Stock status</label><select id="inv-status" v-model="list.filters.stock_status" class="field"><option value="">All</option><option value="in_stock">In stock</option><option value="low_stock">Low stock</option><option value="out_of_stock">Out of stock</option></select></div>
            <div><label class="field-label" for="inv-loc">Location</label><select id="inv-loc" v-model="list.filters.location_id" class="field"><option value="">All locations</option><option v-for="l in lookups.locations" :key="l.id" :value="l.id">{{ l.name }}</option></select></div>
            <div><label class="field-label" for="inv-sort">Sort by</label><select id="inv-sort" v-model="list.filters.sort" class="field"><option value="size">Size</option><option value="brand">Brand</option><option value="available_desc">Most available</option><option value="price_asc">Price, low to high</option><option value="price_desc">Price, high to low</option><option value="updated">Recently changed</option></select></div>
        </div>
        <label class="mb-3 flex items-center gap-2 text-sm text-muted"><input v-model="list.filters.include_archived" type="checkbox" true-value="1" false-value="" class="size-4" /> Include archived tyres</label>

        <section class="panel overflow-hidden">
            <AsyncState :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" empty-title="No tyres found" empty-text="Change the filters, or add a tyre to start building the inventory." @retry="list.load()">
                <template #empty>
                    <RouterLink v-if="auth.can('inventory.manage')" to="/inventory/new" class="btn btn-primary"><Plus class="size-4" /> Add tyre</RouterLink>
                </template>
                <ProductResults :items="list.items.value" />
                <PaginationBar :page="list.page.value" :last-page="list.lastPage.value" :total="list.total.value" @change="list.load" />
            </AsyncState>
        </section>
    </div>
</template>
