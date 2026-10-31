// Web Push, "Notifications on this device" (Profile → Notifications). The server sends what Telegram would
// (PushNotifications on the backend); public/sw.js shows it. Permission is asked only when the switch is tapped.
import { router } from '@inertiajs/vue3';
import { xsrf } from './echo';
import { standalone } from './pwa';
import { inTelegram } from './telegram';

/** unsupported: no Web Push here (old browser, inside Telegram) · install: iPhone / iPad, push only in the installed app ·
 *  denied: blocked in the browser / phone settings · off · on */
export type PushState = 'unsupported' | 'install' | 'denied' | 'off' | 'on';

const ios = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);

const supported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

async function registration(): Promise<ServiceWorkerRegistration | null> {
    if (!('serviceWorker' in navigator)) return null;
    try {
        if (!(await navigator.serviceWorker.getRegistration('/'))) await navigator.serviceWorker.register('/sw.js');
        return await navigator.serviceWorker.ready; // subscribe needs an active worker
    } catch {
        return null;
    }
}

async function current(): Promise<PushSubscription | null> {
    if (!supported()) return null;
    const reg = await navigator.serviceWorker.getRegistration('/');
    return reg ? reg.pushManager.getSubscription() : null;
}

/** The state of this device; $known: the endpoints the server has for the logged-in person. */
export async function pushState(known: string[]): Promise<PushState> {
    if (typeof window === 'undefined' || inTelegram()) return 'unsupported'; // the bot already messages them there
    if (ios() && !standalone()) return 'install';
    if (!supported()) return 'unsupported';
    if (Notification.permission === 'denied') return 'denied';
    const sub = await current();
    return sub && Notification.permission === 'granted' && known.includes(sub.endpoint) ? 'on' : 'off';
}

function keyBytes(base64url: string): Uint8Array<ArrayBuffer> {
    const b64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(b64);
    const out = new Uint8Array(new ArrayBuffer(raw.length));
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}

function sameKey(sub: PushSubscription, key: Uint8Array): boolean {
    const k = sub.options.applicationServerKey;
    if (!k) return false;
    const a = new Uint8Array(k);
    return a.length === key.length && a.every((v, i) => v === key[i]);
}

async function send(method: 'POST' | 'DELETE', body: object): Promise<boolean> {
    const res = await fetch(route(method === 'POST' ? 'me.push.subscribe' : 'me.push.unsubscribe'), {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify(body),
    });
    return res.ok;
}

/** Switch on (call from the tap: the browser asks for permission here). Returns the new state and the endpoint. */
export async function enablePush(publicKey: string): Promise<{ state: PushState; endpoint?: string }> {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return { state: permission === 'denied' ? 'denied' : 'off' };
    const reg = await registration();
    if (!reg) return { state: 'unsupported' };
    const key = keyBytes(publicKey);
    let sub = await reg.pushManager.getSubscription();
    if (sub && !sameKey(sub, key)) {
        await sub.unsubscribe(); // made with old VAPID keys: the server could not reach it
        sub = null;
    }
    sub ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
    const json = sub.toJSON();
    const encoding = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings?.includes('aes128gcm')
        ? 'aes128gcm'
        : 'aesgcm';
    const ok = await send('POST', { endpoint: sub.endpoint, keys: json.keys, contentEncoding: encoding });
    return ok ? { state: 'on', endpoint: sub.endpoint } : { state: 'off' };
}

/** Switch off: the server forgets this device and the browser drops the subscription. */
export async function disablePush(): Promise<PushState> {
    const sub = await current();
    if (sub) {
        await send('DELETE', { endpoint: sub.endpoint }).catch(() => false);
        await sub.unsubscribe().catch(() => false);
    }
    return 'off';
}

// Log out (any "Log out" button, Inertia POST /logout): send this device's endpoint, so the server stops pushing
// the person's news to it, and drop the browser's subscription.
let endpoint: string | null = null;
export function installPush() {
    if (typeof window === 'undefined' || !supported()) return;
    current()
        .then((s) => (endpoint = s?.endpoint ?? null))
        .catch(() => {});
    router.on('before', (e) => {
        const visit = e.detail.visit;
        if (visit.method !== 'post' || !endpoint || visit.url.pathname !== new URL(route('logout')).pathname) return;
        visit.data = { ...(visit.data as Record<string, unknown>), push_endpoint: endpoint };
        current()
            .then((s) => s?.unsubscribe())
            .catch(() => {});
        endpoint = null;
    });
}

/** Remember the endpoint after switching on (for the logout above). */
export function rememberPushEndpoint(e: string | null) {
    endpoint = e;
}
