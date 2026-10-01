/* Cache public assets only. Never cache authenticated HTML, questions or answers. */
const CACHE = 'candidate-assets-v2';
self.addEventListener('install', event => {
 event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(['/portal-offline.html','/branding/logo-512.png','/branding/logo-192.png'])));
});
self.addEventListener('activate', event => {
 event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('candidate-assets-') && key !== CACHE).map(key => caches.delete(key)))));
});
self.addEventListener('fetch', event => {
 const url = new URL(event.request.url);
 if (event.request.method !== 'GET' || url.origin !== self.location.origin) return;
 if (event.request.mode === 'navigate') {
  event.respondWith(fetch(event.request).catch(() => caches.match('/portal-offline.html')));
 } else if (url.pathname.startsWith('/build/assets/')) {
  event.respondWith(caches.open(CACHE).then(async cache => {
   const cached = await cache.match(event.request);
   if (cached) return cached;
   const response = await fetch(event.request);
   if (response.ok) await cache.put(event.request, response.clone());
   return response;
  }));
 }
});
