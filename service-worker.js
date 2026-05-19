/**
 * Head of AI — Service Worker (production).
 *
 * Strategy:
 *   - HTML pages           → network-first, fall back to cached shell.
 *   - CSS / JS (?v=hash)   → cache-first, immutable (the hash query
 *                            string changes on every build → fresh URL).
 *   - Fonts / images       → stale-while-revalidate.
 *   - api/*                → never cached; always pass through.
 *
 * Deliberately NO `beforeinstallprompt` (the spec is silent for B2B
 * landing pages and we don't want a "Install Head of AI" toast popping up).
 */

const VERSION = 'headofai-sw-v2';
const PRECACHE = VERSION + '-precache';
const RUNTIME  = VERSION + '-runtime';

const PRECACHE_URLS = [
  '/',
  '/index.html',
  '/404.html',
  '/manifest.webmanifest',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(PRECACHE)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .catch(() => null)
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== PRECACHE && k !== RUNTIME).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

function isApiRequest(url) {
  return url.pathname.startsWith('/api/');
}

function isHtmlRequest(req) {
  if (req.mode === 'navigate') return true;
  const accept = req.headers.get('accept') || '';
  return accept.includes('text/html');
}

function isStaticAsset(url) {
  return /\.(css|js|woff2?|ttf|otf|png|jpg|jpeg|webp|avif|svg|ico)$/i.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // 1. Never cache same-origin API or third-party tracking
  if (url.origin !== self.location.origin || isApiRequest(url)) return;

  // 2. HTML → network-first
  if (isHtmlRequest(req)) {
    event.respondWith(networkFirst(req));
    return;
  }

  // 3. Static asset → cache-first (immutable when ?v= is present)
  if (isStaticAsset(url)) {
    event.respondWith(cacheFirst(req));
    return;
  }
});

async function networkFirst(req) {
  try {
    const fresh = await fetch(req);
    const cache = await caches.open(RUNTIME);
    cache.put(req, fresh.clone());
    return fresh;
  } catch {
    const cached = await caches.match(req);
    if (cached) return cached;
    const fallback = await caches.match('/404.html');
    return fallback || new Response('Offline', { status: 503, statusText: 'Offline' });
  }
}

async function cacheFirst(req) {
  const cached = await caches.match(req);
  if (cached) {
    // Refresh in the background (stale-while-revalidate behaviour)
    fetch(req).then((res) => {
      if (res && res.ok) caches.open(RUNTIME).then((c) => c.put(req, res.clone()));
    }).catch(() => null);
    return cached;
  }
  try {
    const res = await fetch(req);
    if (res && res.ok) {
      const cache = await caches.open(RUNTIME);
      cache.put(req, res.clone());
    }
    return res;
  } catch {
    return new Response('', { status: 504 });
  }
}
