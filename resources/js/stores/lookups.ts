import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { get } from '@/api/http';
import type { Lookups } from '@/types';
import { applyBrand } from '@/utils/brand';

export const useLookups = defineStore('lookups', () => {
    const data = ref<Lookups | null>(null);
    const loading = ref(false);

    async function load(force = false) {
        if (data.value && !force) return data.value;
        loading.value = true;
        try {
            data.value = await get<Lookups>('/lookups');
            applyBrand(data.value.settings.brand_color, data.value.settings.brand_ink);
        } finally {
            loading.value = false;
        }
        return data.value;
    }

    const settings = computed(() => data.value?.settings);
    const brands = computed(() => data.value?.brands ?? []);
    const activeBrands = computed(() => brands.value.filter((b) => b.is_active));
    const locations = computed(() => data.value?.locations ?? []);
    const activeLocations = computed(() => locations.value.filter((l) => l.is_active));
    const sellableLocations = computed(() => activeLocations.value.filter((l) => l.is_sellable));
    const suppliers = computed(() => data.value?.suppliers ?? []);
    const employees = computed(() => data.value?.employees ?? []);
    const serviceCodes = computed(() => data.value?.service_codes ?? {});
    const tyreTypes = computed(() => data.value?.tyre_types ?? {});
    const movementTypes = computed(() => data.value?.movement_types ?? {});

    return { data, loading, load, settings, brands, activeBrands, locations, activeLocations, sellableLocations, suppliers, employees, serviceCodes, tyreTypes, movementTypes };
});
