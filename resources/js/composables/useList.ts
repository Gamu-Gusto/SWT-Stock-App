import { reactive, ref, watch } from 'vue';
import { get } from '@/api/http';
import type { ApiError } from '@/api/http';

/**
 * Loads a paginated server list. Filters are reactive; text filters are debounced so the
 * server (not the browser) does the searching.
 */
export function useList<T = any>(url: string | (() => string), initialFilters: Record<string, unknown> = {}, opts: { debounceKeys?: string[]; immediate?: boolean } = {}) {
    const items = ref<T[]>([]) as { value: T[] };
    const loading = ref(false);
    const error = ref<string | null>(null);
    const page = ref(1);
    const lastPage = ref(1);
    const total = ref<number | undefined>(undefined);
    const filters = reactive({ ...initialFilters });
    const extra = ref<Record<string, any>>({});
    let requestId = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    async function load(p = page.value) {
        const id = ++requestId;
        loading.value = true;
        error.value = null;
        try {
            const res = await get<any>(typeof url === 'function' ? url() : url, { ...filters, page: p });
            if (id !== requestId) return; // a newer search has started
            items.value = res.data ?? [];
            const meta = res.meta ?? res;
            page.value = meta.current_page ?? 1;
            lastPage.value = meta.last_page ?? 1;
            total.value = meta.total;
            extra.value = res;
        } catch (e) {
            if (id === requestId) error.value = (e as ApiError).message;
        } finally {
            if (id === requestId) loading.value = false;
        }
    }

    const debounceKeys = opts.debounceKeys ?? ['q'];
    watch(
        () => ({ ...filters }),
        (now, before) => {
            const textChanged = debounceKeys.some((k) => now[k] !== before?.[k]);
            clearTimeout(timer);
            timer = setTimeout(() => load(1), textChanged ? 300 : 0);
        },
    );

    if (opts.immediate !== false) load(1);

    return { items, loading, error, page, lastPage, total, filters, extra, load };
}
