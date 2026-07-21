/*
 * Service worker de la caisse (POS) Dolibarr Maroc.
 *
 * Cache d'exécution (aucune liste d'assets figée) :
 *  - navigations SPA  → réseau d'abord, repli sur le shell mis en cache ;
 *  - assets buildés /build/… (hashés, immuables) → cache d'abord ;
 *  - API GET (session, produits, niveaux, entrepôts) → réseau d'abord, repli cache.
 *
 * Les requêtes non-GET (POST /pos/ventes…) ne sont jamais mises en cache :
 * la file d'attente hors-ligne côté application s'en charge.
 *
 * NB : appareil de caisse mono-utilisateur — les réponses API authentifiées
 * mises en cache ne doivent pas être partagées entre comptes sur un même poste.
 */
const CACHE = 'dolibarr-pos-v1';
const SHELL = ['/caisse', '/'];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL).catch(() => {})));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

function putInCache(request, response) {
    const copy = response.clone();
    caches.open(CACHE).then((cache) => cache.put(request, copy));
    return response;
}

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Navigations SPA : réseau d'abord, repli sur le shell en cache.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((res) => putInCache(request, res))
                .catch(() => caches.match(request).then((r) => r || caches.match('/caisse') || caches.match('/'))),
        );
        return;
    }

    // Assets buildés : cache d'abord (noms hashés, immuables).
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((res) => putInCache(request, res))),
        );
        return;
    }

    // API GET : réseau d'abord, repli sur la dernière réponse en cache.
    if (url.pathname.startsWith('/api/')) {
        event.respondWith(
            fetch(request)
                .then((res) => putInCache(request, res))
                .catch(() => caches.match(request)),
        );
    }
});
