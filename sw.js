/* Atlas service worker: keeps what a student has opened, so a second visit (and the app) needs almost
 * nothing from the server, and the whole Atlas works offline once its pack is downloaded.
 *  - pages: network first (fresh content), the saved copy when offline
 *  - hashed code (/assets/), 3D models (/models/ ?v=N), figures: saved copy first — these never change
 *    under the same address
 * Everything is same-origin GET; sign-in and sync calls to mbbsadda.in are never touched. */
const SHELL = 'atlas-shell';
const ASSETS = 'atlas-assets';
const MEDIA = 'atlas-media';
const modelsCache = (v) => `atlas-models-v${v || '0'}`;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

async function fromAnyCache(req) {
  return caches.match(req, { ignoreVary: true });
}

async function cacheFirst(req, cacheName) {
  const hit = await fromAnyCache(req);
  if (hit) return hit;
  const res = await fetch(req);
  if (res.ok && res.type === 'basic') {
    const c = await caches.open(cacheName);
    c.put(req, res.clone()).catch(() => {});
  }
  return res;
}

async function networkFirstPage(req) {
  const c = await caches.open(SHELL);
  try {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 6000);
    const res = await fetch(req.url, { signal: ctrl.signal, cache: 'no-store', credentials: 'same-origin' }); // never an old page from the HTTP cache
    clearTimeout(t);
    if (res.ok) c.put('/', res.clone()).catch(() => {});
    return res;
  } catch {
    return (await c.match('/')) || (await c.match('/index.html')) || new Response('<h1>Offline</h1><p>Connect once to open the Atlas.</p>', { headers: { 'Content-Type': 'text/html' } });
  }
}

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  const p = url.pathname;
  if (req.mode === 'navigate') { e.respondWith(networkFirstPage(req)); return; }
  if (p.startsWith('/preview/') || p === '/sw.js' || p === '/offline.json') return;
  if (p.startsWith('/assets/')) { e.respondWith(cacheFirst(req, ASSETS)); return; }
  if (p.startsWith('/models/')) {
    const v = url.searchParams.get('v');
    e.respondWith((async () => {
      const res = await cacheFirst(req, modelsCache(v));
      // a newer model version replaces the old one: drop older model caches once the new one is in use
      if (v) caches.keys().then((ks) => ks.filter((k) => k.startsWith('atlas-models-v') && k !== modelsCache(v)).forEach((k) => caches.delete(k))).catch(() => {});
      return res;
    })());
    return;
  }
  if (p.startsWith('/fig/') || p.startsWith('/stations/') || p === '/logo.svg') { e.respondWith(cacheFirst(req, MEDIA)); return; }
});
