import axios, { AxiosError, type AxiosRequestConfig } from 'axios';
import type { ApiErrorShape } from '@/types';

/**
 * Same-origin API client. The session cookie authenticates; the XSRF-TOKEN cookie set by Laravel
 * is sent back automatically as the X-XSRF-TOKEN header (CSRF protection).
 */
export const http = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    timeout: 30000,
});

export class ApiError extends Error implements ApiErrorShape {
    status: number;
    code?: string;
    errors?: Record<string, string[]>;
    context?: Record<string, unknown> | null;

    constructor(shape: ApiErrorShape) {
        super(shape.message);
        this.status = shape.status;
        this.code = shape.code;
        this.errors = shape.errors;
        this.context = shape.context;
    }

    /** First validation message for a field (supports "items.0.quantity" keys). */
    field(name: string): string | undefined {
        return this.errors?.[name]?.[0];
    }
}

type Handler = (error: ApiError) => void;
const listeners: Record<'unauthenticated' | 'passwordChange', Handler[]> = { unauthenticated: [], passwordChange: [] };
export function onApiEvent(event: keyof typeof listeners, handler: Handler) {
    listeners[event].push(handler);
}

function normalise(error: unknown): ApiError {
    if (error instanceof ApiError) return error;
    const ax = error as AxiosError<{ message?: string; code?: string; errors?: Record<string, string[]>; context?: Record<string, unknown> }>;
    if (!ax.response) {
        const offline = typeof navigator !== 'undefined' && !navigator.onLine;
        return new ApiError({
            status: 0,
            code: 'network',
            message: offline
                ? 'You are offline. Stock changes need an internet connection – nothing was saved. Reconnect and try again.'
                : 'The server could not be reached. Nothing was saved. Check the connection and try again.',
        });
    }
    const { status, data } = ax.response;
    let message = data?.message || 'Something went wrong. Please try again.';
    if (status === 419) message = 'Your session expired. Refresh the page and try again.';
    if (status === 429) message = 'Too many attempts. Wait a minute and try again.';
    if (status === 422 && data?.errors && message.includes('(and')) message = Object.values(data.errors)[0]?.[0] ?? message;

    return new ApiError({ status, message, code: data?.code, errors: data?.errors, context: data?.context });
}

http.interceptors.response.use(
    (r) => r,
    (error) => {
        const err = normalise(error);
        if (err.status === 401) listeners.unauthenticated.forEach((h) => h(err));
        if (err.status === 403 && err.code === 'password_change_required') listeners.passwordChange.forEach((h) => h(err));
        return Promise.reject(err);
    },
);

export async function get<T = any>(url: string, params?: Record<string, unknown>, config?: AxiosRequestConfig): Promise<T> {
    const clean = params ? Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined)) : undefined;
    return (await http.get<T>(url, { ...config, params: clean })).data;
}
export async function post<T = any>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
    return (await http.post<T>(url, body, config)).data;
}
export async function put<T = any>(url: string, body?: unknown): Promise<T> {
    return (await http.put<T>(url, body)).data;
}
export async function del<T = any>(url: string): Promise<T> {
    return (await http.delete<T>(url)).data;
}

/** Unique key per form submission so retries or double-taps never post a transaction twice. */
export function idempotencyKey(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID();
    return `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}

/** Build a URL for a file download (exports) with query parameters. */
export function downloadUrl(path: string, params: Record<string, unknown> = {}): string {
    const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null).map(([k, v]) => [k, String(v)]));
    return `/api/v1${path}${qs.toString() ? `?${qs}` : ''}`;
}
