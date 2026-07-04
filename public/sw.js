// Minimal service worker: caches the app shell so the PWA is installable and
// the static UI loads instantly. All /api/ traffic always goes to the network
// — this app is fundamentally online-only (it drives a remote Claude Code run).
const CACHE = 'book-manager-shell-v1';
const SHELL = ['/', '/app.html', '/assets/css/app.css', '/assets/js/app.js', '/assets/js/markdown.js'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (url.pathname.startsWith('/api/')) return; // never cache API responses

  event.respondWith(
    caches.match(event.request).then((cached) => cached || fetch(event.request))
  );
});
