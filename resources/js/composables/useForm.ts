import { ref } from 'vue';
import { ApiError, idempotencyKey } from '@/api/http';
import { useUi } from '@/stores/ui';

/**
 * Submission helper: prevents double submits, keeps one idempotency key per form until it
 * succeeds, and exposes field errors from the server's validation response.
 */
export function useSubmit() {
    const busy = ref(false);
    const error = ref<ApiError | null>(null);
    const key = ref(idempotencyKey());
    const ui = useUi();

    async function run<T>(fn: (key: string) => Promise<T>, success?: string): Promise<T | undefined> {
        if (busy.value) return undefined;
        busy.value = true;
        error.value = null;
        try {
            const result = await fn(key.value);
            key.value = idempotencyKey();
            if (success) ui.toast(success);
            return result;
        } catch (e) {
            error.value = e as ApiError;
            if (!(e as ApiError).errors) ui.toast((e as ApiError).message, 'error');
            return undefined;
        } finally {
            busy.value = false;
        }
    }

    const fieldError = (name: string) => error.value?.field(name);

    return { busy, error, run, fieldError, key };
}
