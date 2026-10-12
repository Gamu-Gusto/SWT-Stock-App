<script setup lang="ts">
import { reactive } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { KeyRound } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import FormField from '@/components/ui/FormField.vue';
import { put } from '@/api/http';
import { useAuth } from '@/stores/auth';
import { useSubmit } from '@/composables/useForm';
import { formatDateTime } from '@/utils/format';
import type { User } from '@/types';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const profile = reactive({ name: auth.user?.name ?? '', phone: auth.user?.phone ?? '' });
const pw = reactive({ current_password: '', password: '', password_confirmation: '' });
const profileForm = useSubmit();
const pwForm = useSubmit();

async function saveProfile() {
    const res = await profileForm.run(() => put<{ user: User }>('/auth/profile', profile), 'Profile saved.');
    if (res) auth.setUser(res.user);
}

async function savePassword() {
    const res = await pwForm.run(() => put<{ user: User }>('/auth/password', pw), 'Password changed. Other devices have been signed out.');
    if (res) {
        auth.setUser(res.user);
        Object.assign(pw, { current_password: '', password: '', password_confirmation: '' });
        if (route.query.force) router.replace('/');
    }
}
</script>

<template>
    <div class="max-w-3xl">
        <PageHeader title="Profile and password" :subtitle="`${auth.user?.email} · ${auth.user?.role.label}`" />

        <div v-if="auth.user?.must_change_password" class="mb-5 flex gap-3 rounded-[10px] border border-warn/30 bg-warn-soft p-4 text-sm text-warn" role="alert">
            <KeyRound class="size-5 shrink-0" />
            <p><strong>Change your temporary password to continue.</strong> It was set by an administrator and only you should know your password.</p>
        </div>

        <section class="panel mb-5">
            <div class="panel-head"><h2 class="panel-title">Change password</h2></div>
            <form class="grid gap-4 p-5 sm:grid-cols-3" @submit.prevent="savePassword">
                <FormField v-slot="{ id }" label="Current password" :error="pwForm.fieldError('current_password')">
                    <input :id="id" v-model="pw.current_password" type="password" class="field" autocomplete="current-password" required />
                </FormField>
                <FormField v-slot="{ id }" label="New password" :error="pwForm.fieldError('password')" hint="10+ characters, letters and numbers">
                    <input :id="id" v-model="pw.password" type="password" class="field" autocomplete="new-password" required />
                </FormField>
                <FormField v-slot="{ id }" label="Confirm new password">
                    <input :id="id" v-model="pw.password_confirmation" type="password" class="field" autocomplete="new-password" required />
                </FormField>
                <div class="sm:col-span-3"><button class="btn btn-dark" :disabled="pwForm.busy.value">Change password</button></div>
            </form>
        </section>

        <section v-if="!auth.user?.must_change_password" class="panel">
            <div class="panel-head"><h2 class="panel-title">Your details</h2><span class="text-sm text-muted">Last sign-in {{ formatDateTime(auth.user?.last_login_at) }}</span></div>
            <form class="grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="saveProfile">
                <FormField v-slot="{ id }" label="Full name" :error="profileForm.fieldError('name')">
                    <input :id="id" v-model="profile.name" class="field" required />
                </FormField>
                <FormField v-slot="{ id }" label="Phone" :error="profileForm.fieldError('phone')">
                    <input :id="id" v-model="profile.phone" type="tel" class="field" />
                </FormField>
                <div class="sm:col-span-2"><button class="btn btn-secondary" :disabled="profileForm.busy.value">Save details</button></div>
            </form>
        </section>
    </div>
</template>
