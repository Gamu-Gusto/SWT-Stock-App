import { defineStore } from 'pinia';
import { ref } from 'vue';
import { get } from '@/api/http';

export interface Toast {
    id: number;
    kind: 'success' | 'error' | 'info';
    message: string;
}

export const useUi = defineStore('ui', () => {
    const toasts = ref<Toast[]>([]);
    const unread = ref(0);
    const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
    let seq = 0;

    function toast(message: string, kind: Toast['kind'] = 'success', ms = 4500) {
        const id = ++seq;
        toasts.value.push({ id, kind, message });
        setTimeout(() => dismiss(id), kind === 'error' ? ms + 3000 : ms);
    }
    function dismiss(id: number) {
        toasts.value = toasts.value.filter((t) => t.id !== id);
    }

    async function refreshUnread() {
        try {
            unread.value = (await get<{ unread: number }>('/notifications/summary')).unread;
        } catch {
            /* ignore – the notification badge is non-critical */
        }
    }

    if (typeof window !== 'undefined') {
        window.addEventListener('online', () => (online.value = true));
        window.addEventListener('offline', () => (online.value = false));
    }

    return { toasts, toast, dismiss, unread, refreshUnread, online };
});
