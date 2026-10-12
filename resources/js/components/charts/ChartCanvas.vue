<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Chart, BarController, BarElement, LineController, LineElement, PointElement, DoughnutController, ArcElement,
    CategoryScale, LinearScale, Tooltip, Legend, Filler, type ChartConfiguration,
} from 'chart.js';

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, DoughnutController, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Filler);
Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
Chart.defaults.color = '#5b616e';

/** Thin Chart.js wrapper; `label` is the accessible description of what the chart shows. */
const props = defineProps<{ config: ChartConfiguration; label: string; height?: number }>();
const canvas = ref<HTMLCanvasElement | null>(null);
let chart: Chart | null = null;

function render() {
    chart?.destroy();
    if (canvas.value) chart = new Chart(canvas.value, { ...props.config, options: { responsive: true, maintainAspectRatio: false, animation: false, ...props.config.options } });
}
onMounted(render);
watch(() => props.config, render, { deep: true });
onBeforeUnmount(() => chart?.destroy());
</script>

<template>
    <div :style="{ height: `${height ?? 240}px` }" class="relative">
        <canvas ref="canvas" role="img" :aria-label="label" />
    </div>
</template>
