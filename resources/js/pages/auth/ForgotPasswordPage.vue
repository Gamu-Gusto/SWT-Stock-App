<script setup lang="ts">
import { ref } from 'vue';
import AuthShell from '@/layouts/AuthShell.vue';
import FormField from '@/components/ui/FormField.vue';
import { post, type ApiError } from '@/api/http';

const email = ref('');
const message = ref('');
const error = ref<ApiError | null>(null);
const busy = ref(false);

async function submit() {
    busy.value = true;
    error.value = null;
    try {
        message.value = (await post<{ message: string }>('/auth/forgot-password', { email: email.value })).message;
    } catch (e) {
        error.value = e as ApiError;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthShell title="Reset your password" subtitle="We will e-mail you a reset link if e-mail is set up for this system.">
        <p v-if="message" class="rounded-[6px] bg-ok-soft px-3 py-3 text-sm text-ok">{{ message }}</p>
        <form v-else class="space-y-4" @submit.prevent="submit">
            <FormField v-slot="{ id }" label="E-mail address" :error="error?.field('email') ?? error?.message">
                <input :id="id" v-model="email" type="email" class="field" required autofocus />
            </FormField>
            <button type="submit" class="btn btn-primary w-full" :disabled="busy || !email">Send reset link</button>
        </form>
        <p class="mt-6 text-sm text-muted">No e-mail? Ask the owner or a manager to give you a temporary password from Users.</p>
        <RouterLink to="/login" class="link mt-4 inline-block text-sm">Back to sign in</RouterLink>
    </AuthShell>
</template>
