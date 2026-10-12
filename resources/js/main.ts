import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import { onApiEvent } from './api/http';
import { useAuth } from './stores/auth';

const app = createApp(App);
const pinia = createPinia();
app.use(pinia);
app.use(router);

// Session expired or account deactivated → back to sign-in.
onApiEvent('unauthenticated', () => {
    const auth = useAuth();
    if (auth.user) {
        auth.$patch({ user: null });
        const current = router.currentRoute.value;
        if (!current.meta.guest) router.push({ name: 'login', query: { redirect: current.fullPath, expired: '1' } });
    }
});
onApiEvent('passwordChange', () => {
    router.push({ name: 'profile', query: { force: '1' } });
});

app.mount('#app');

// PWA: the service worker caches only versioned static assets; stock data always comes from the server.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    });
}
