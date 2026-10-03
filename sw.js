/*
 * obitickets service worker.
 *
 * Deliberately small and conservative — this is a ticketing site with money
 * and logins, so the worker must never be able to serve a stale or someone
 * else's page:
 *
 *  - HTML pages (every PHP page: checkout, orders, tickets, admin, API
 *    responses) always go to the network. They are never stored. If the
 *    network is down for a page navigation, the visitor sees /offline.html.
 *  - Only same-origin GET requests are ever touched; POSTs (checkout, login,
 *    payment polling) pass straight through untouched.
 *  - Static files are cached for speed: CSS/JS network-first (a deploy shows
 *    up immediately; the cached copy is only a fallback), images and event
 *    uploads cache-first.
 *
 * Bump CACHE_VERSION to drop every cached file on the next visit.
 */
const CACHE_VERSION = 'v1';
const STATIC_CACHE = 'obitickets-static-' + CACHE_VERSION;
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll([OFFLINE_URL, '/assets/icons/icon-192.png']))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k.startsWith('obitickets-') && k !== STATIC_CACHE).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

function isStaticAsset(url) {
  return /^\/assets\//.test(url.pathname) || /^\/uploads\//.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;      // fonts, analytics, etc: not ours
  if (url.pathname.startsWith('/api/')) return;          // payment polling etc: always live

  // Page navigations: network only, offline page as the fallback.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }

  if (!isStaticAsset(url)) return;

  const isCodeFile = /\.(css|js)$/.test(url.pathname);
  if (isCodeFile) {
    // network-first: always the newest deploy, cached copy only if offline
    event.respondWith(
      fetch(req).then((res) => {
        if (res.ok) { const copy = res.clone(); caches.open(STATIC_CACHE).then((c) => c.put(req, copy)); }
        return res;
      }).catch(() => caches.match(req))
    );
    return;
  }

  // images / uploads / icons: cache-first
  event.respondWith(
    caches.match(req).then((hit) => hit || fetch(req).then((res) => {
      if (res.ok) { const copy = res.clone(); caches.open(STATIC_CACHE).then((c) => c.put(req, copy)); }
      return res;
    }))
  );
});
