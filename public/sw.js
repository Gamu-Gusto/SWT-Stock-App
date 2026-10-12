/*
 * Super Wheels and Tyres – service worker.
 *
 * Deliberately conservative: it caches ONLY versioned static files (/build/*, /icons/*, /images/* logo files, fonts).
 * API responses (stock quantities, prices, sales) are NEVER cached, so the app can never show
 * stale stock as current. Offline stock changes are not supported: when the network is down the
 * app shows a clear "offline" message and nothing is saved.
 */
const CACHE = 'swt-static-v2';
const STATIC = /^\/(build|icons|images)\/|^\/(favicon\.ico|manifest\.webmanifest)$/;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll(['/icons/icon-192.png', '/manifest.webmanifest'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== self.location.origin) return;
    if (url.pathname.startsWith('/api/')) return; // always network – authoritative stock data

    if (STATIC.test(url.pathname)) {
        // Hashed build files never change: cache first.
        event.respondWith(
            caches.match(event.request).then((hit) => hit || fetch(event.request).then((res) => {
                if (res.ok) {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(event.request, copy));
                }
                return res;
            })),
        );
        return;
    }

    if (event.request.mode === 'navigate') {
        // Pages always come from the network (they carry the session/CSRF token).
        event.respondWith(fetch(event.request).catch(() => new Response(
            '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title>'
            + '<body style="font-family:system-ui;padding:32px;background:#eef0f2;color:#1d2128">'
            + '<h1>You are offline</h1><p>The stock system needs an internet connection to show live stock and save transactions. '
            + 'Nothing has been saved. Reconnect and reload this page.</p></body>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 },
        )));
    }
});
