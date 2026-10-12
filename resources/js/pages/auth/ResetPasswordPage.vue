<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthShell from '@/layouts/AuthShell.vue';
import FormField from '@/components/ui/FormField.vue';
import { post, type ApiError } from '@/api/http';
import { useUi } from '@/stores/ui';

const route = useRoute();
const router = useRouter();
const ui = useUi();
const form = reactive({ email: String(route.query.email ?? ''), password: '', password_confirmation: '' });
const error = ref<ApiError | null>(null);
const busy = ref(false);

async function submit() {
    busy.value = true;
    error.value = null;
    try {
        const res = await post<{ message: string }>('/auth/reset-password', { ...form, token: route.params.token });
        ui.toast(res.message);
        router.replace({ name: 'login' });
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthShell title="Choose a new password" subtitle="At least 10 characters, with letters and numbers.">
        <form class="space-y-4" @submit.prevent="submit">
            <FormField v-slot="{ id }" label="E-mail address" :error="error?.field('email')">
                <input :id="id" v-model="form.email" type="email" class="field" required />
            </FormField>
            <FormField v-slot="{ id }" label="New password" :error="error?.field('password')">
                <input :id="id" v-model="form.password" type="password" class="field" autocomplete="new-password" required />
            </FormField>
            <FormField v-slot="{ id }" label="Confirm new password">
                <input :id="id" v-model="form.password_confirmation" type="password" class="field" autocomplete="new-password" required />
            </FormField>
            <p v-if="error && !error.errors" class="field-error">{{ error.message }}</p>
            <button type="submit" class="btn btn-primary w-full" :disabled="busy">Save new password</button>
        </form>
    </AuthShell>
</template>
