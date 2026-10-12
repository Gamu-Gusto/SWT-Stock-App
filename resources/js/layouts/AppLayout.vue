<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Bell, LogOut, Menu, Search, UserRound, WifiOff, X } from 'lucide-vue-next';
import { useAuth } from '@/stores/auth';
import { useLookups } from '@/stores/lookups';
import { useUi } from '@/stores/ui';
import { mainNav, mobileNav } from '@/router/navigation';

const auth = useAuth();
const lookups = useLookups();
const ui = useUi();
const route = useRoute();
const router = useRouter();

const drawer = ref(false);
const userMenu = ref(false);
const search = ref('');

const nav = computed(() => mainNav.filter((i) => !i.can || auth.can(...i.can)));
const bottom = computed(() => mobileNav.filter((i) => !i.can || auth.can(...i.can)));
const businessName = computed(() => lookups.settings?.business_name ?? 'Super Wheels and Tyres');
const logo = computed(() => lookups.settings?.logo_url ?? '/images/swt-logo-160.webp');

function isActive(to: string) {
    return to === '/' ? route.path === '/' : route.path === to || route.path.startsWith(`${to}/`);
}

function submitSearch() {
    if (!search.value.trim()) return;
    router.push({ name: 'search', query: { q: search.value.trim() } });
    search.value = '';
}

async function signOut() {
    await auth.logout();
    router.replace({ name: 'login' });
}

watch(() => route.fullPath, () => {
    drawer.value = false;
    userMenu.value = false;
});

let poll: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    ui.refreshUnread();
    poll = setInterval(() => ui.refreshUnread(), 60000);
    if (route.query.denied) ui.toast('You do not have access to that page.', 'error');
});
onUnmounted(() => clearInterval(poll));
</script>

<template>
    <div class="min-h-screen lg:pl-64">
        <!-- Sidebar (desktop) / drawer (mobile) -->
        <div v-if="drawer" class="fixed inset-0 z-30 bg-asphalt/50 lg:hidden" @click="drawer = false" />
        <aside
            class="no-print fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-asphalt text-white transition-transform lg:translate-x-0"
            :class="drawer ? 'translate-x-0' : '-translate-x-full'"
            aria-label="Main navigation"
        >
            <RouterLink to="/" class="flex h-[72px] items-center gap-3 border-b border-white/10 px-4" aria-label="Dashboard">
                <img :src="logo" alt="" class="size-12 shrink-0 rounded-full object-contain" width="48" height="48" />
                <div class="min-w-0 leading-tight">
                    <span class="block truncate font-display text-[17px] font-bold">{{ businessName }}</span>
                    <span class="block text-xs text-brand">Stock management</span>
                </div>
            </RouterLink>
                <button type="button" class="absolute top-5 right-2 rounded p-2 hover:bg-white/10 lg:hidden" aria-label="Close menu" @click="drawer = false"><X class="size-5" /></button>
            <nav class="flex-1 overflow-y-auto px-2 py-3">
                <RouterLink
                    v-for="item in nav"
                    :key="item.to"
                    :to="item.to"
                    class="mb-0.5 flex items-center gap-3 rounded-[6px] px-3 py-2.5 text-[14.5px] font-medium"
                    :class="isActive(item.to) ? 'bg-white text-asphalt' : 'text-white/80 hover:bg-white/10 hover:text-white'"
                    :aria-current="isActive(item.to) ? 'page' : undefined"
                >
                    <component :is="item.icon" class="size-[18px]" :class="isActive(item.to) ? 'text-brand-text' : ''" />
                    {{ item.label }}
                </RouterLink>
            </nav>
            <div class="border-t border-white/10 px-4 py-3 text-xs text-white/50">
                Signed in as {{ auth.user?.name }}<br />{{ auth.user?.role.label }}
            </div>
        </aside>

        <!-- Top bar -->
        <header class="no-print sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-line bg-panel/95 px-3 backdrop-blur sm:px-5">
            <button type="button" class="btn btn-ghost !px-2 lg:hidden" aria-label="Open menu" @click="drawer = true"><Menu class="size-6" /></button>
            <RouterLink to="/" class="flex min-w-0 items-center gap-2 lg:hidden">
                <img :src="logo" alt="" class="size-9 shrink-0 rounded-full" width="36" height="36" />
                <span class="truncate font-display text-lg font-bold">{{ businessName }}</span>
            </RouterLink>

            <form v-if="auth.can('inventory.view')" class="ml-auto hidden max-w-md flex-1 sm:block lg:ml-0" role="search" @submit.prevent="submitSearch">
                <label for="global-search" class="sr-only">Search tyres</label>
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-[18px] -translate-y-1/2 text-faint" />
                    <input id="global-search" v-model="search" type="search" class="field !min-h-10 !py-2 !pl-10" placeholder="Tyre size, brand or SKU – 205/55R16" />
                </div>
            </form>

            <div class="ml-auto flex items-center gap-1">
                <RouterLink to="/notifications" class="btn btn-ghost relative !px-2.5" aria-label="Notifications">
                    <Bell class="size-5" />
                    <span v-if="ui.unread" class="absolute top-1 right-1 min-w-[18px] rounded-full bg-brand px-1 text-center text-[11px] leading-[18px] font-bold text-brand-ink">{{ ui.unread > 99 ? '99+' : ui.unread }}</span>
                </RouterLink>
                <div class="relative">
                    <button type="button" class="btn btn-ghost !px-2.5" aria-haspopup="menu" :aria-expanded="userMenu" @click="userMenu = !userMenu">
                        <UserRound class="size-5" /><span class="hidden text-sm md:inline">{{ auth.user?.name }}</span>
                    </button>
                    <div v-if="userMenu" role="menu" class="absolute right-0 mt-1 w-56 rounded-[10px] border border-line bg-panel py-1 shadow-lg">
                        <RouterLink to="/profile" role="menuitem" class="flex items-center gap-2 px-4 py-2.5 text-sm hover:bg-surface"><UserRound class="size-4" /> Profile and password</RouterLink>
                        <button type="button" role="menuitem" class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm hover:bg-surface" @click="signOut"><LogOut class="size-4" /> Sign out</button>
                    </div>
                </div>
            </div>
        </header>

        <div v-if="!ui.online" class="no-print flex items-center gap-2 bg-warn px-4 py-2 text-sm font-semibold text-white" role="alert">
            <WifiOff class="size-4" /> You are offline. Stock figures may be out of date and nothing can be saved until the connection returns.
        </div>

        <main class="mx-auto max-w-[1400px] px-4 pt-5 pb-28 sm:px-6 lg:pb-10">
            <RouterView v-slot="{ Component }">
                <component :is="Component" :key="route.path" />
            </RouterView>
        </main>

        <!-- Mobile quick actions -->
        <nav class="no-print fixed inset-x-0 bottom-0 z-20 flex border-t border-line bg-panel pb-[env(safe-area-inset-bottom)] lg:hidden" aria-label="Quick actions">
            <RouterLink
                v-for="item in bottom"
                :key="item.to"
                :to="item.to"
                class="flex min-h-[60px] flex-1 flex-col items-center justify-center gap-0.5 text-[11.5px] font-semibold"
                :class="isActive(item.to) ? 'text-brand-text' : 'text-muted'"
            >
                <component :is="item.icon" class="size-[22px]" />
                {{ item.label }}
            </RouterLink>
        </nav>
    </div>
</template>
