<script setup lang="ts">
import { ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import TabLinks from '@/components/ui/TabLinks.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import { useList } from '@/composables/useList';
import { useLookups } from '@/stores/lookups';
import { formatDateTime } from '@/utils/format';

const lookups = useLookups();
const list = useList<any>('/audit-logs', { q: '', user_id: '', action: '', date_from: '', date_to: '' }, { debounceKeys: ['q', 'action'] });
const expanded = ref<number | null>(null);
</script>

<template>
    <div>
        <PageHeader title="Audit log" subtitle="Who did what, and when. Entries cannot be changed. Passwords and secrets are never recorded." />
        <TabLinks :tabs="[{ label: 'Employees', to: '/users', can: ['users.view'] }, { label: 'Roles and permissions', to: '/users/roles', can: ['users.view'] }, { label: 'Audit log', to: '/audit' }]" />
        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
            <div class="col-span-2 md:col-span-1"><label class="field-label" for="a-q">Search</label><input id="a-q" v-model="list.filters.q" type="search" class="field" /></div>
            <div><label class="field-label" for="a-u">Employee</label><select id="a-u" v-model="list.filters.user_id" class="field"><option value="">Everyone</option><option v-for="e in lookups.employees" :key="e.id" :value="e.id">{{ e.name }}</option></select></div>
            <div><label class="field-label" for="a-a">Action</label><select id="a-a" v-model="list.filters.action" class="field"><option value="">All</option><option value="auth">Sign-ins</option><option value="product">Tyres</option><option value="receipt">Receiving</option><option value="sale">Sales</option><option value="reservation">Reservations</option><option value="adjustment">Adjustments</option><option value="stocktake">Stocktakes</option><option value="user">Users</option><option value="role">Permissions</option><option value="settings">Settings</option></select></div>
            <div><label class="field-label" for="a-f">From</label><input id="a-f" v-model="list.filters.date_from" type="date" class="field" /></div>
            <div><label class="field-label" for="a-t">To</label><input id="a-t" v-model="list.filters.date_to" type="date" class="field" /></div>
        </div>
        <section class="panel overflow-hidden">
            <AsyncState :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" empty-title="No entries" @retry="list.load()">
                <ul class="divide-y divide-line-soft text-sm">
                    <li v-for="l in list.items.value" :key="l.id" class="px-5 py-3">
                        <div class="flex flex-wrap items-baseline gap-x-3">
                            <span class="w-36 shrink-0 text-muted">{{ formatDateTime(l.created_at) }}</span>
                            <span class="font-semibold">{{ l.user?.name ?? 'System' }}</span>
                            <span class="flex-1">{{ l.description }}</span>
                            <span class="text-[12px] text-faint">{{ l.action }}<template v-if="l.ip_address"> · {{ l.ip_address }}</template></span>
                            <button v-if="l.old_values || l.new_values" type="button" class="link text-[13px]" @click="expanded = expanded === l.id ? null : l.id">{{ expanded === l.id ? 'Hide' : 'Changes' }}</button>
                        </div>
                        <div v-if="expanded === l.id" class="mt-2 grid gap-2 sm:grid-cols-2">
                            <pre v-if="l.old_values" class="overflow-x-auto rounded-[6px] bg-bad-soft/60 p-2 text-[12px]">Before: {{ JSON.stringify(l.old_values, null, 2) }}</pre>
                            <pre v-if="l.new_values" class="overflow-x-auto rounded-[6px] bg-ok-soft/60 p-2 text-[12px]">After: {{ JSON.stringify(l.new_values, null, 2) }}</pre>
                        </div>
                    </li>
                </ul>
                <PaginationBar :page="list.page.value" :last-page="list.lastPage.value" :total="list.total.value" @change="list.load" />
            </AsyncState>
        </section>
    </div>
</template>
