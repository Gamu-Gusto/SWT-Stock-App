<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { UserPlus, KeyRound, Power, Pencil } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import TabLinks from '@/components/ui/TabLinks.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import FormField from '@/components/ui/FormField.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { get, post, put } from '@/api/http';
import { useList } from '@/composables/useList';
import { useSubmit } from '@/composables/useForm';
import { useAuth } from '@/stores/auth';
import { formatDateTime } from '@/utils/format';

const auth = useAuth();
const list = useList<any>('/users', { q: '', role_id: '', active: '' });
const roles = ref<any[]>([]);
onMounted(async () => (roles.value = (await get('/roles')).data));

const submit = useSubmit();
const editing = ref<any>(null);
const open = ref(false);
const form = reactive({ name: '', email: '', phone: '', role_id: '' as number | '' });
const temp = ref<{ name: string; password: string } | null>(null);

function create() {
    editing.value = null;
    Object.assign(form, { name: '', email: '', phone: '', role_id: '' });
    open.value = true;
}
function edit(u: any) {
    editing.value = u;
    Object.assign(form, { name: u.name, email: u.email, phone: u.phone ?? '', role_id: u.role?.id ?? '' });
    open.value = true;
}
async function save() {
    const res = await submit.run(() => (editing.value ? put(`/users/${editing.value.id}`, form) : post('/users', form)), editing.value ? 'Employee updated.' : 'Employee account created.');
    if (res) {
        open.value = false;
        if (res.temporary_password) temp.value = { name: res.data.name, password: res.temporary_password };
        list.load();
    }
}
async function toggle(u: any) {
    if (u.is_active && !confirm(`Deactivate ${u.name}? They are signed out immediately and cannot sign in.`)) return;
    if (await submit.run(() => post(`/users/${u.id}/${u.is_active ? 'deactivate' : 'activate'}`), u.is_active ? 'Account deactivated.' : 'Account activated.')) list.load();
}
async function reset(u: any) {
    if (!confirm(`Create a new temporary password for ${u.name}? Their current password stops working.`)) return;
    const res = await submit.run(() => post(`/users/${u.id}/reset-password`));
    if (res) temp.value = { name: u.name, password: res.temporary_password };
}
</script>

<template>
    <div>
        <PageHeader title="Users" subtitle="Employee accounts. Only administrators can create accounts – there is no public sign-up.">
            <template #actions><button v-if="auth.can('users.manage')" type="button" class="btn btn-primary" @click="create"><UserPlus class="size-4" /> Add employee</button></template>
        </PageHeader>
        <TabLinks :tabs="[{ label: 'Employees', to: '/users' }, { label: 'Roles and permissions', to: '/users/roles' }, { label: 'Audit log', to: '/audit', can: ['audit.view'] }]" />
        <div class="mb-4 grid grid-cols-2 gap-3 sm:max-w-2xl sm:grid-cols-3">
            <div><label class="field-label" for="u-q">Search</label><input id="u-q" v-model="list.filters.q" type="search" class="field" /></div>
            <div><label class="field-label" for="u-r">Role</label><select id="u-r" v-model="list.filters.role_id" class="field"><option value="">All roles</option><option v-for="r in roles" :key="r.id" :value="r.id">{{ r.label }}</option></select></div>
            <div><label class="field-label" for="u-a">Status</label><select id="u-a" v-model="list.filters.active" class="field"><option value="">All</option><option value="1">Active</option><option value="0">Deactivated</option></select></div>
        </div>

        <div v-if="temp" class="mb-4 rounded-[10px] border border-ok/30 bg-ok-soft p-4 text-sm" role="alert">
            <p class="font-semibold text-ok">Temporary password for {{ temp.name }}</p>
            <p class="mt-1 font-display text-2xl font-bold tracking-wide select-all">{{ temp.password }}</p>
            <p class="mt-1 text-muted">Give it to the employee privately. It is shown only once and must be changed at first sign-in.</p>
            <button type="button" class="link mt-2" @click="temp = null">I have noted it – hide</button>
        </div>

        <section class="panel overflow-hidden">
            <AsyncState :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" empty-title="No employees found" @retry="list.load()">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Name</th><th>Role</th><th>Last sign-in</th><th>Status</th><th v-if="auth.can('users.manage')"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody>
                            <tr v-for="u in list.items.value" :key="u.id">
                                <td><p class="font-semibold">{{ u.name }}</p><p class="text-[12.5px] text-muted">{{ u.email }}</p></td>
                                <td>{{ u.role?.label }}</td>
                                <td class="text-[13px]">{{ formatDateTime(u.last_login_at) }}</td>
                                <td><StatusBadge :status="u.is_active ? 'active' : 'cancelled'" :text="u.is_active ? (u.must_change_password ? 'Active – temp. password' : 'Active') : 'Deactivated'" /></td>
                                <td v-if="auth.can('users.manage')" class="whitespace-nowrap">
                                    <div class="flex justify-end gap-1">
                                        <button type="button" class="btn btn-ghost btn-sm" @click="edit(u)"><Pencil class="size-4" /> Edit</button>
                                        <button type="button" class="btn btn-ghost btn-sm" @click="reset(u)"><KeyRound class="size-4" /> Reset password</button>
                                        <button v-if="u.id !== auth.user?.id" type="button" class="btn btn-ghost btn-sm" :class="u.is_active ? 'text-bad' : ''" @click="toggle(u)"><Power class="size-4" /> {{ u.is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AsyncState>
        </section>

        <ModalDialog :open="open" :title="editing ? `Edit ${editing.name}` : 'Add employee'" @close="open = false">
            <form id="user-form" class="grid gap-3" @submit.prevent="save">
                <FormField v-slot="{ id }" label="Full name" required :error="submit.fieldError('name')"><input :id="id" v-model="form.name" class="field" required /></FormField>
                <FormField v-slot="{ id }" label="E-mail (used to sign in)" required :error="submit.fieldError('email')"><input :id="id" v-model="form.email" type="email" class="field" required /></FormField>
                <FormField v-slot="{ id }" label="Phone"><input :id="id" v-model="form.phone" type="tel" class="field" /></FormField>
                <FormField v-slot="{ id }" label="Role" required :error="submit.fieldError('role_id')">
                    <select :id="id" v-model="form.role_id" class="field" required><option value="" disabled>Choose role</option><option v-for="r in roles" :key="r.id" :value="r.id" :disabled="r.name === 'owner' && auth.user?.role.name !== 'owner'">{{ r.label }}</option></select>
                </FormField>
                <p class="text-sm text-muted">{{ roles.find((r) => r.id === form.role_id)?.description }}</p>
                <p v-if="!editing" class="text-sm text-muted">A temporary password is generated. The employee must change it when they first sign in.</p>
            </form>
            <template #footer><button type="button" class="btn btn-secondary" @click="open = false">Cancel</button><button type="submit" form="user-form" class="btn btn-primary" :disabled="submit.busy.value">{{ editing ? 'Save' : 'Create account' }}</button></template>
        </ModalDialog>
    </div>
</template>
