// ZoneSpot service worker: makes the site installable ("Add to Home Screen", app icon, full screen) and shows
// Web Push notifications ("Notifications on this device" in Profile, sent by PushNotifications on the server).
// It caches nothing on purpose: every page and asset comes from the network, so a deploy is live at once and
// nobody is stuck on an old build.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (e) => {
    if (e.request.mode === 'navigate') e.respondWith(fetch(e.request));
});

// payload: { title, body, icon, tag, renotify, data: { url } } (NotificationChannels\WebPush\WebPushMessage)
self.addEventListener('push', (e) => {
    let p = {};
    try {
        p = e.data ? e.data.json() : {};
    } catch {
        p = { body: e.data ? e.data.text() : '' };
    }
    if (!p || typeof p !== 'object') p = { body: String(p ?? '') }; // e.g. a JSON null or a bare string
    const options = {
        body: p.body || '',
        icon: p.icon || '/icons/icon-192.png',
        badge: p.badge,
        tag: p.tag,
        renotify: Boolean(p.tag && p.renotify),
        data: { url: (p.data && p.data.url) || '/' },
    };
    e.waitUntil(self.registration.showNotification(p.title || 'ZoneSpot', options));
});

// a tap: an open app window goes to the page (focused), otherwise a new window opens it
self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    // only pages of this site: anything else opens the home page
    const u = new URL(e.notification.data?.url || '/', self.location.origin);
    const url = u.origin === self.location.origin ? u.href : self.location.origin + '/';
    e.waitUntil(
        (async () => {
            const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            const win = wins.find((w) => new URL(w.url).origin === self.location.origin);
            if (win) {
                await win.focus();
                if (win.url !== url && 'navigate' in win) {
                    try {
                        await win.navigate(url);
                    } catch {
                        await self.clients.openWindow(url); // not controlled by this worker
                    }
                }
                return;
            }
            await self.clients.openWindow(url);
        })(),
    );
});

// the browser replaced the subscription (expired keys…): subscribe again and tell the server, which knows the old
// endpoint (no session or CSRF token here: /push/renew accepts the old endpoint as the proof)
self.addEventListener('pushsubscriptionchange', (e) => {
    e.waitUntil(
        (async () => {
            const old = e.oldSubscription;
            const key = old && old.options && old.options.applicationServerKey;
            if (!old || !key) return; // nothing to prove it with: the person switches it on again in Profile
            const sub = e.newSubscription || (await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }));
            const json = sub.toJSON();
            const enc = (self.PushManager?.supportedContentEncodings || []).includes('aes128gcm') ? 'aes128gcm' : 'aesgcm';
            await fetch('/push/renew', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ old_endpoint: old.endpoint, endpoint: sub.endpoint, keys: json.keys, contentEncoding: enc }),
            });
        })().catch(() => {}),
    );
});
