// Bookball service worker: exists so the phone can install the site ("Add to Home Screen", app icon, full screen).
// It caches nothing on purpose: every page and asset comes from the network, so a deploy is live at once and
// nobody is stuck on an old build. Offline pages and push come with the mobile app, not here.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (e) => {
    if (e.request.mode === 'navigate') e.respondWith(fetch(e.request));
});
