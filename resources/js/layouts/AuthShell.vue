<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { applyBrand } from '@/utils/brand';

const brand = ref<{ business_name: string; logo_url: string; brand_color: string; brand_ink: string } | null>(null);
// Built-in logo shown immediately; replaced by an uploaded logo if the owner set one.
const logo = computed(() => brand.value?.logo_url ?? '/images/swt-logo.webp');
onMounted(async () => {
    try {
        brand.value = await (await fetch('/branding', { headers: { Accept: 'application/json' } })).json();
        applyBrand(brand.value?.brand_color, brand.value?.brand_ink);
    } catch {
        brand.value = null;
    }
});
defineProps<{ title: string; subtitle?: string }>();
</script>

<template>
    <div class="grid min-h-screen lg:grid-cols-[1fr_minmax(0,560px)]">
        <!-- Brand side: the Super Wheels and Tyres badge on black -->
        <section class="relative hidden flex-col items-center justify-center overflow-hidden bg-black p-12 text-center text-white lg:flex" aria-hidden="true">
            <img :src="logo" alt="" class="size-[min(440px,60vh)] rounded-full object-contain" width="440" height="440" />
            <p class="mt-8 font-display text-3xl font-bold">{{ brand?.business_name ?? 'Super Wheels and Tyres' }}</p>
            <p class="mt-1 font-display text-lg tracking-wide text-brand">Stock management · Pretoria West</p>
            <p class="absolute bottom-10 max-w-sm text-sm text-white/50">Stock, sales, reservations and stocktakes for the workshop – from the counter, the racks or the office.</p>
        </section>

        <section class="flex items-center justify-center bg-panel px-5 py-10 sm:px-10">
            <div class="w-full max-w-sm">
                <div class="mb-8 flex flex-col items-center text-center lg:hidden">
                    <img :src="logo" :alt="brand?.business_name ?? 'Super Wheels and Tyres'" class="size-32 rounded-full object-contain" width="128" height="128" />
                    <p class="mt-3 font-display text-xl font-bold">{{ brand?.business_name ?? 'Super Wheels and Tyres' }}</p>
                </div>
                <h1 class="text-[30px] font-bold">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1 mb-6 text-muted">{{ subtitle }}</p>
                <slot />
            </div>
        </section>
    </div>
</template>
