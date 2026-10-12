<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import TabLinks from '@/components/ui/TabLinks.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import { get, put, type ApiError } from '@/api/http';
import { useAuth } from '@/stores/auth';
import { useSubmit } from '@/composables/useForm';

const auth = useAuth();
const roles = ref<any[]>([]);
const permissions = ref<{ name: string; label: string; group: string }[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const submit = useSubmit();
const canEdit = auth.can('roles.manage');

async function load() {
    loading.value = true;
    try {
        const res = await get('/roles');
        roles.value = res.data.map((r: any) => ({ ...r, permissions: [...r.permissions] }));
        permissions.value = res.permissions;
    } catch (e) {
        error.value = (e as ApiError).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const groups = computed(() => {
    const g: Record<string, typeof permissions.value> = {};
    for (const p of permissions.value) (g[p.group] ??= []).push(p);
    return g;
});
function toggle(role: any, perm: string) {
    const i = role.permissions.indexOf(perm);
    if (i >= 0) role.permissions.splice(i, 1);
    else role.permissions.push(perm);
    role.dirty = true;
}
async function save(role: any) {
    if (await submit.run(() => put(`/roles/${role.id}`, { permissions: role.permissions }), `${role.label} permissions saved.`)) role.dirty = false;
}
</script>

<template>
    <div>
        <PageHeader title="Users" subtitle="What each role may do. The server enforces these permissions on every request." />
        <TabLinks :tabs="[{ label: 'Employees', to: '/users' }, { label: 'Roles and permissions', to: '/users/roles' }, { label: 'Audit log', to: '/audit', can: ['audit.view'] }]" />
        <AsyncState :loading="loading" :error="error" @retry="load">
            <p class="mb-3 text-sm text-muted">The owner always has every permission. Financial access for managers (cost prices, margins, stock value) is controlled by “View cost prices, margins and inventory value” and “Inventory valuation report”.</p>
            <section class="panel overflow-x-auto">
                <table class="data-table">
                    <thead class="sticky top-16 z-10">
                        <tr><th class="min-w-[260px]">Permission</th><th v-for="r in roles" :key="r.id" class="text-center">{{ r.label }}<div class="font-normal">{{ r.users_count }} user{{ r.users_count === 1 ? '' : 's' }}</div></th></tr>
                    </thead>
                    <tbody>
                        <template v-for="(perms, group) in groups" :key="group">
                            <tr><td :colspan="roles.length + 1" class="bg-surface font-display text-base font-semibold">{{ group }}</td></tr>
                            <tr v-for="p in perms" :key="p.name">
                                <td>{{ p.label }}</td>
                                <td v-for="r in roles" :key="r.id" class="text-center">
                                    <input type="checkbox" class="size-5" :checked="r.permissions.includes(p.name)" :disabled="r.locked || !canEdit" :aria-label="`${r.label}: ${p.label}`" @change="toggle(r, p.name)" />
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot v-if="canEdit"><tr><td></td><td v-for="r in roles" :key="r.id" class="py-3 text-center"><button v-if="!r.locked" type="button" class="btn btn-sm" :class="r.dirty ? 'btn-primary' : 'btn-secondary'" :disabled="!r.dirty || submit.busy.value" @click="save(r)">Save</button></td></tr></tfoot>
                </table>
            </section>
        </AsyncState>
    </div>
</template>
