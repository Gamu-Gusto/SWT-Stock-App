<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Search, PackagePlus, Truck, ShoppingCart, ClipboardCheck, BarChart3, CirclePlus, TriangleAlert, Ban, CalendarClock, ClipboardList } from 'lucide-vue-next';
import type { ChartConfiguration } from 'chart.js';
import ChartCanvas from '@/components/charts/ChartCanvas.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import { get, type ApiError } from '@/api/http';
import { useAuth } from '@/stores/auth';
import { formatDate, formatMoney } from '@/utils/format';

const SERIES_1 = '#2a78d6';
const SERIES_2 = '#eb6834';
const GRID = '#e8ebef';

interface Dashboard {
    stats: Record<string, number>;
    charts: {
        sales_over_time: { date: string; tyres: number; sales: number; revenue: string | null }[];
        stock_by_brand: { label: string; physical: number; available: number }[];
        stock_by_size: { label: string; physical: number; available: number }[];
        new_vs_used: { label: string; physical: number }[];
        movement_trend: { date: string; in: number; out: number }[];
    };
    my_stocktakes: { id: number; stocktake_no: string; title: string }[];
    financial: null | {
        revenue_today: string;
        revenue_month: string;
        gross_profit_month: string | null;
        inventory_cost_value: string | null;
        top_sizes: { label: string; qty: number }[];
        top_brands: { label: string; qty: number }[];
    };
}

const auth = useAuth();
const router = useRouter();
const data = ref<Dashboard | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const size = ref('');

async function load() {
    loading.value = true;
    error.value = null;
    try {
        data.value = (await get<{ data: Dashboard }>('/dashboard')).data;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

function search() {
    router.push({ name: 'search', query: size.value.trim() ? { q: size.value.trim() } : {} });
}

const actions = computed(() =>
    [
        { label: 'Add tyre', to: '/inventory/new', icon: CirclePlus, can: ['inventory.manage'] },
        { label: 'Receive stock', to: '/receiving/new', icon: Truck, can: ['receiving.create'] },
        { label: 'Search tyre', to: '/search', icon: Search, can: ['inventory.view'] },
        { label: 'Record sale', to: '/sales/new', icon: ShoppingCart, can: ['sales.create'] },
        { label: 'Start stocktake', to: '/stocktakes/new', icon: ClipboardCheck, can: ['stocktake.manage'] },
        { label: 'Generate report', to: '/reports', icon: BarChart3, can: ['reports.operational', 'reports.sales', 'reports.financial'] },
    ].filter((a) => auth.can(...a.can)),
);

const shortDate = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}`;
const axis = { grid: { color: GRID }, border: { display: false }, ticks: { precision: 0 } };

const salesChart = computed<ChartConfiguration>(() => ({
    type: 'bar',
    data: {
        labels: data.value!.charts.sales_over_time.map((d) => shortDate(d.date)),
        datasets: [{ label: 'Tyres sold', data: data.value!.charts.sales_over_time.map((d) => d.tyres), backgroundColor: SERIES_1, borderRadius: 4, maxBarThickness: 18 }],
    },
    options: { plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }, y: axis } },
}));

const movementChart = computed<ChartConfiguration>(() => ({
    type: 'line',
    data: {
        labels: data.value!.charts.movement_trend.map((d) => shortDate(d.date)),
        datasets: [
            { label: 'Stock in', data: data.value!.charts.movement_trend.map((d) => d.in), borderColor: SERIES_1, backgroundColor: SERIES_1, borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, tension: 0.25 },
            { label: 'Stock out', data: data.value!.charts.movement_trend.map((d) => d.out), borderColor: SERIES_2, backgroundColor: SERIES_2, borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, tension: 0.25, borderDash: [6, 3] },
        ],
    },
    options: {
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top', align: 'end', labels: { boxWidth: 12, usePointStyle: true } } },
        scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }, y: axis },
    },
}));

function horizontalBars(rows: { label: string; available: number }[]): ChartConfiguration {
    return {
        type: 'bar',
        data: { labels: rows.map((r) => r.label), datasets: [{ label: 'Available tyres', data: rows.map((r) => r.available), backgroundColor: SERIES_1, borderRadius: 4, maxBarThickness: 16 }] },
        options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: axis, y: { grid: { display: false } } } },
    };
}

const newUsed = computed(() => {
    const rows = data.value?.charts.new_vs_used ?? [];
    const total = rows.reduce((s, r) => s + r.physical, 0) || 1;
    return rows.map((r) => ({ ...r, pct: Math.round((r.physical / total) * 100) }));
});

const salesTotal = computed(() => data.value?.charts.sales_over_time.reduce((s, d) => s + d.tyres, 0) ?? 0);
</script>

<template>
    <div>
        <!-- The counter question, first -->
        <section v-if="auth.can('inventory.view')" class="mb-6 rounded-[12px] bg-asphalt px-5 py-6 text-white sm:px-8 sm:py-8">
            <h1 class="text-[28px] font-bold sm:text-[36px]">Which tyre does the customer need?</h1>
            <form class="mt-4 flex flex-col gap-2 sm:flex-row" role="search" @submit.prevent="search">
                <label for="dash-size" class="sr-only">Tyre size</label>
                <input
                    id="dash-size"
                    v-model="size"
                    class="field !min-h-14 flex-1 !border-0 !font-display !text-2xl !font-semibold !tracking-wide"
                    placeholder="205/55 R16"
                    inputmode="text"
                    autocomplete="off"
                />
                <button class="btn btn-primary !min-h-14 px-6 text-base"><Search class="size-5" /> Check stock</button>
            </form>
            <p class="mt-2 text-sm text-white/60">Type it any way: 205/55R16, 205 55 16, R16, “Falken 205/55 R16” or “used 205/55 R16”.</p>
        </section>

        <AsyncState :loading="loading" :error="error" @retry="load">
            <template v-if="data">
                <div v-if="data.my_stocktakes.length" class="mb-5 rounded-[10px] border border-info/30 bg-info-soft p-4">
                    <p class="font-semibold text-info">Stocktakes assigned to you</p>
                    <RouterLink v-for="s in data.my_stocktakes" :key="s.id" :to="`/stocktakes/${s.id}/count`" class="mt-2 flex items-center gap-2 font-medium text-ink hover:underline">
                        <ClipboardList class="size-4" /> {{ s.stocktake_no }} – {{ s.title }}
                    </RouterLink>
                </div>

                <!-- Stock position -->
                <section class="mb-6" aria-labelledby="stock-heading">
                    <h2 id="stock-heading" class="sr-only">Stock position</h2>
                    <div class="grid grid-cols-2 overflow-hidden rounded-[10px] border border-line bg-panel md:grid-cols-4 xl:grid-cols-6">
                        <div class="border-r border-b border-line-soft p-4">
                            <p class="text-sm text-muted">Tyres in stock</p>
                            <p class="mt-1 font-display text-[34px] leading-none font-bold tabular-nums">{{ data.stats.total_physical }}</p>
                            <p class="mt-1 text-[13px] text-muted">{{ data.stats.total_variants }} tyre records</p>
                        </div>
                        <div class="border-r border-b border-line-soft p-4">
                            <p class="text-sm text-muted">Available to sell</p>
                            <p class="mt-1 font-display text-[34px] leading-none font-bold tabular-nums">{{ data.stats.total_available }}</p>
                            <p class="mt-1 text-[13px] text-muted">{{ data.stats.total_reserved }} reserved</p>
                        </div>
                        <RouterLink to="/inventory?stock_status=low_stock" class="border-r border-b border-line-soft p-4 hover:bg-surface">
                            <p class="flex items-center gap-1 text-sm text-muted"><TriangleAlert class="size-4 text-warn" /> Low stock</p>
                            <p class="mt-1 font-display text-[34px] leading-none font-bold tabular-nums">{{ data.stats.low_stock_variants }}</p>
                            <p class="mt-1 text-[13px] text-muted">at or below minimum</p>
                        </RouterLink>
                        <RouterLink to="/inventory?stock_status=out_of_stock" class="border-r border-b border-line-soft p-4 hover:bg-surface">
                            <p class="flex items-center gap-1 text-sm text-muted"><Ban class="size-4 text-bad" /> Out of stock</p>
                            <p class="mt-1 font-display text-[34px] leading-none font-bold tabular-nums">{{ data.stats.out_of_stock_variants }}</p>
                            <p class="mt-1 text-[13px] text-muted">tyre records</p>
                        </RouterLink>
                        <div class="border-r border-b border-line-soft p-4">
                            <p class="text-sm text-muted">Received today</p>
                            <p class="mt-1 font-display text-[34px] leading-none font-bold tabular-nums">{{ data.stats.received_today }}</p>
                            <p class="mt-1 text-[13px] text-muted">tyres booked in</p>
                        </div>
                        <div class="border-b border-line-soft p-4">
                            <p class="text-sm text-muted">Sold today</p>
                            <p class="mt-1 font-display text-[34px] leading-none font-bold tabular-nums">{{ data.stats.sold_today }}</p>
                            <p class="mt-1 text-[13px] text-muted">{{ data.stats.sold_month }} this month</p>
                        </div>
                    </div>
                </section>

                <div class="mb-6 grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <!-- Quick actions -->
                    <section class="panel">
                        <div class="panel-head"><h2 class="panel-title">Quick actions</h2></div>
                        <div class="grid grid-cols-2 gap-2 p-4 sm:grid-cols-3">
                            <RouterLink v-for="a in actions" :key="a.to" :to="a.to" class="flex min-h-14 items-center gap-3 rounded-[8px] border border-line px-3 py-3 font-semibold hover:border-asphalt hover:bg-surface">
                                <component :is="a.icon" class="size-5 text-brand-text" /> {{ a.label }}
                            </RouterLink>
                        </div>
                    </section>
                    <!-- Work waiting -->
                    <section class="panel">
                        <div class="panel-head"><h2 class="panel-title">Waiting on someone</h2></div>
                        <ul class="divide-y divide-line-soft">
                            <li><RouterLink to="/stocktakes" class="flex items-center justify-between px-5 py-3 hover:bg-surface"><span class="flex items-center gap-2"><ClipboardCheck class="size-4 text-muted" /> Stocktakes not finished</span><span class="qty">{{ data.stats.pending_stocktakes }}</span></RouterLink></li>
                            <li><RouterLink to="/stocktakes/approvals" class="flex items-center justify-between px-5 py-3 hover:bg-surface"><span class="flex items-center gap-2"><TriangleAlert class="size-4 text-muted" /> Approvals pending</span><span class="qty">{{ data.stats.pending_approvals }}</span></RouterLink></li>
                            <li><RouterLink to="/reservations" class="flex items-center justify-between px-5 py-3 hover:bg-surface"><span class="flex items-center gap-2"><CalendarClock class="size-4 text-muted" /> Active reservations</span><span class="qty">{{ data.stats.active_reservations }}</span></RouterLink></li>
                        </ul>
                    </section>
                </div>

                <!-- Money (management only) -->
                <section v-if="data.financial" class="mb-6" aria-labelledby="money-heading">
                    <h2 id="money-heading" class="mb-3 text-xl font-semibold">Sales and value</h2>
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <div class="panel p-4"><p class="text-sm text-muted">Revenue today</p><p class="mt-1 font-display text-2xl font-bold tabular-nums">{{ formatMoney(data.financial.revenue_today) }}</p></div>
                        <div class="panel p-4"><p class="text-sm text-muted">Revenue this month</p><p class="mt-1 font-display text-2xl font-bold tabular-nums">{{ formatMoney(data.financial.revenue_month) }}</p></div>
                        <div v-if="data.financial.gross_profit_month !== null" class="panel p-4"><p class="text-sm text-muted">Estimated gross profit (month)</p><p class="mt-1 font-display text-2xl font-bold tabular-nums">{{ formatMoney(data.financial.gross_profit_month) }}</p></div>
                        <div v-if="data.financial.inventory_cost_value !== null" class="panel p-4"><p class="text-sm text-muted">Stock at cost (FIFO)</p><p class="mt-1 font-display text-2xl font-bold tabular-nums">{{ formatMoney(data.financial.inventory_cost_value) }}</p></div>
                    </div>
                </section>

                <div class="grid gap-6 xl:grid-cols-2">
                    <section class="panel">
                        <div class="panel-head"><h2 class="panel-title">Tyres sold per day</h2><span class="text-sm text-muted">last 30 days · {{ salesTotal }} total</span></div>
                        <div class="p-4"><ChartCanvas :config="salesChart" :label="`Bar chart of tyres sold per day over the last 30 days, ${salesTotal} in total.`" /></div>
                    </section>
                    <section class="panel">
                        <div class="panel-head"><h2 class="panel-title">Stock in and out</h2><span class="text-sm text-muted">last 30 days, excl. transfers</span></div>
                        <div class="p-4"><ChartCanvas :config="movementChart" label="Line chart comparing tyres received or added with tyres sold or removed per day over the last 30 days." /></div>
                    </section>
                    <section class="panel">
                        <div class="panel-head"><h2 class="panel-title">Available by brand</h2></div>
                        <div class="p-4">
                            <ChartCanvas v-if="data.charts.stock_by_brand.length" :config="horizontalBars(data.charts.stock_by_brand)" label="Available tyres per brand." :height="Math.max(160, data.charts.stock_by_brand.length * 30)" />
                            <p v-else class="py-8 text-center text-sm text-muted">No stock recorded yet.</p>
                        </div>
                    </section>
                    <section class="panel">
                        <div class="panel-head"><h2 class="panel-title">Available by size</h2><span class="text-sm text-muted">top 12</span></div>
                        <div class="p-4">
                            <ChartCanvas v-if="data.charts.stock_by_size.length" :config="horizontalBars(data.charts.stock_by_size)" label="Available tyres per tyre size." :height="Math.max(160, data.charts.stock_by_size.length * 30)" />
                            <p v-else class="py-8 text-center text-sm text-muted">No stock recorded yet.</p>
                        </div>
                    </section>
                    <section class="panel">
                        <div class="panel-head"><h2 class="panel-title">New and used tyres in stock</h2></div>
                        <div class="space-y-3 p-5">
                            <div v-for="r in newUsed" :key="r.label">
                                <div class="mb-1 flex justify-between text-sm"><span class="font-semibold">{{ r.label }}</span><span class="tabular-nums">{{ r.physical }} tyres · {{ r.pct }}%</span></div>
                                <div class="h-2.5 overflow-hidden rounded-full bg-line-soft"><div class="h-full rounded-full" :style="{ width: `${r.pct}%`, background: r.label === 'New' ? SERIES_1 : SERIES_2 }" /></div>
                            </div>
                            <p v-if="!newUsed.length" class="text-sm text-muted">No stock recorded yet.</p>
                        </div>
                    </section>
                    <section v-if="data.financial" class="panel">
                        <div class="panel-head"><h2 class="panel-title">Best sellers</h2><span class="text-sm text-muted">last 30 days</span></div>
                        <div class="grid gap-6 p-5 sm:grid-cols-2">
                            <div>
                                <h3 class="mb-2 text-base font-semibold">Sizes</h3>
                                <ol class="space-y-1.5 text-sm">
                                    <li v-for="r in data.financial.top_sizes" :key="r.label" class="flex justify-between"><span class="size-chip text-sm">{{ r.label }}</span><span class="tabular-nums">{{ r.qty }} sold</span></li>
                                    <li v-if="!data.financial.top_sizes.length" class="text-muted">No sales yet.</li>
                                </ol>
                            </div>
                            <div>
                                <h3 class="mb-2 text-base font-semibold">Brands</h3>
                                <ol class="space-y-1.5 text-sm">
                                    <li v-for="r in data.financial.top_brands" :key="r.label" class="flex justify-between"><span class="font-medium">{{ r.label }}</span><span class="tabular-nums">{{ r.qty }} sold</span></li>
                                    <li v-if="!data.financial.top_brands.length" class="text-muted">No sales yet.</li>
                                </ol>
                            </div>
                        </div>
                    </section>
                </div>
                <p class="mt-4 text-right text-xs text-muted">Figures calculated live on {{ formatDate(new Date().toISOString()) }}. Reload for the latest stock.</p>
            </template>
        </AsyncState>
    </div>
</template>
