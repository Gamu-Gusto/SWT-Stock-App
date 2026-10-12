<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { LogIn } from 'lucide-vue-next';
import AuthShell from '@/layouts/AuthShell.vue';
import FormField from '@/components/ui/FormField.vue';
import { useAuth } from '@/stores/auth';
import type { ApiError } from '@/api/http';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const email = ref('');
const password = ref('');
const remember = ref(false);
const busy = ref(false);
const error = ref<ApiError | null>(null);

async function submit() {
    busy.value = true;
    error.value = null;
    try {
        const user = await auth.login(email.value, password.value, remember.value);
        const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/';
        router.replace(user.must_change_password ? { name: 'profile', query: { force: '1' } } : redirect);
    } catch (e) {
        error.value = e as ApiError;
        password.value = '';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthShell title="Sign in" subtitle="Use the account the owner created for you.">
        <p v-if="route.query.expired" class="mb-4 rounded-[6px] bg-warn-soft px-3 py-2 text-sm text-warn">Your session ended. Sign in again to continue.</p>
        <form class="space-y-4" novalidate @submit.prevent="submit">
            <FormField v-slot="{ id }" label="E-mail address" :error="error?.field('email') ?? (error && !error.errors ? error.message : undefined)">
                <input :id="id" v-model="email" type="email" class="field" autocomplete="username" required autofocus />
            </FormField>
            <FormField v-slot="{ id }" label="Password" :error="error?.field('password')">
                <input :id="id" v-model="password" type="password" class="field" autocomplete="current-password" required />
            </FormField>
            <label class="flex items-center gap-2 text-sm"><input v-model="remember" type="checkbox" class="size-4 accent-[var(--color-brand-text)]" /> Keep me signed in on this device</label>
            <button type="submit" class="btn btn-primary w-full" :disabled="busy || !email || !password"><LogIn class="size-5" /> {{ busy ? 'Signing in…' : 'Sign in' }}</button>
        </form>
        <RouterLink to="/forgot-password" class="link mt-6 inline-block text-sm">Forgot your password?</RouterLink>
        <p class="mt-8 text-[13px] text-muted">Accounts are created by the owner. There is no public sign-up.</p>
    </AuthShell>
</template>
