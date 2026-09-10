// Simple service worker with basic offline caching for static assets.
const CACHE_NAME = 'mailshield-static-v1';
const PRECACHE_URLS = [
    '/',
    '/index.php',
    '/assets/js/app.js',
    '/assets/css/style.css',
    '/assets/images/favicon.ico',
    '/assets/images/favicon.svg'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(PRECACHE_URLS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => Promise.all(
            keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
        )).then(() => self.clients.claim())
    );
});

// Fetch handler:
// - Navigation requests: network-first (falls back to cached shell)
// - Precached static assets: cache-first
// - Other requests: default to network
self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;

    const requestUrl = new URL(event.request.url);

    // Only handle same-origin requests here
    if (requestUrl.origin !== self.location.origin) return;

    // Navigation (HTML) requests: network-first
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then(response => {
                    // Optionally update cache with fresh shell
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put('/index.php', copy)).catch(() => {});
                    return response;
                })
                .catch(() => caches.match('/index.php').then(resp => resp || caches.match('/')))
        );
        return;
    }

    // Serve precached assets with cache-first strategy
    if (PRECACHE_URLS.includes(requestUrl.pathname)) {
        event.respondWith(
            caches.match(event.request).then(cached => cached || fetch(event.request).then(response => {
                // Update cache for future
                const responseClone = response.clone();
                caches.open(CACHE_NAME).then(cache => cache.put(event.request, responseClone)).catch(() => {});
                return response;
            }))
        );
        return;
    }

    // Otherwise, do a network fetch (you may extend to cache other resources)
});
