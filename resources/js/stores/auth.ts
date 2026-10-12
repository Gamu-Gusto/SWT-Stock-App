import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { get, post } from '@/api/http';
import type { User } from '@/types';

export const useAuth = defineStore('auth', () => {
    const user = ref<User | null>(null);
    const checked = ref(false);

    const isAuthenticated = computed(() => !!user.value);
    const permissions = computed(() => new Set(user.value?.permissions ?? []));

    function can(...perms: string[]): boolean {
        return perms.some((p) => permissions.value.has(p));
    }

    async function fetchUser(): Promise<User | null> {
        try {
            user.value = (await get<{ user: User }>('/auth/me')).user;
        } catch {
            user.value = null;
        } finally {
            checked.value = true;
        }
        return user.value;
    }

    async function login(email: string, password: string, remember: boolean) {
        const res = await post<{ user: User }>('/auth/login', { email, password, remember });
        user.value = res.user;
        checked.value = true;
        return res.user;
    }

    async function logout() {
        try {
            await post('/auth/logout');
        } finally {
            user.value = null;
        }
    }

    function setUser(u: User) {
        user.value = u;
    }

    return { user, checked, isAuthenticated, can, fetchUser, login, logout, setUser };
});
