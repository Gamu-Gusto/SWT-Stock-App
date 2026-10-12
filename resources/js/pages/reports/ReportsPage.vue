<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { FileBarChart } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import { get, type ApiError } from '@/api/http';

const reports = ref<{ key: string; title: string; description: string }[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
async function load() {
    loading.value = true;
    try {
        reports.value = (await get('/reports')).data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Reports" subtitle="Live reports from the stock ledger. Filter, print, or download for Excel." />
        <AsyncState :loading="loading" :error="error" :empty="!reports.length" empty-title="No reports available for your role" @retry="load">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <RouterLink v-for="r in reports" :key="r.key" :to="`/reports/${r.key}`" class="flex gap-3 rounded-[10px] border border-line bg-panel p-4 hover:border-asphalt">
                    <FileBarChart class="mt-0.5 size-6 shrink-0 text-brand-text" />
                    <span><span class="block font-display text-lg font-semibold">{{ r.title }}</span><span class="text-sm text-muted">{{ r.description }}</span></span>
                </RouterLink>
            </div>
        </AsyncState>
    </div>
</template>
