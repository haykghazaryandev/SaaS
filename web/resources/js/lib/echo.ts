import { configureEcho, echo } from '@laravel/echo-vue';
import type Echo from 'laravel-echo';
import { ref } from 'vue';

// Live updates over Laravel Reverb (see README "Real time").
// One connection per logged-in user, shared by window.Echo and the @laravel/echo-vue hooks (useEcho, useEchoNotification).
// Guests get no connection. Started / stopped from app.ts whenever the logged-in user changes.

declare global {
    interface Window {
        Echo?: Echo<'reverb'>;
    }
}

/** the connection state: 'connected', 'connecting', 'unavailable', 'disconnected', … */
export const echoState = ref<string>('disconnected');

let userId: number | null = null;

/** the XSRF cookie Laravel sets on every response (the session's CSRF token, encrypted) */
export function xsrf(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export function startEcho(id: number | null | undefined): void {
    if ((id ?? null) === userId) return;
    stopEcho();
    if (!id || !import.meta.env.VITE_REVERB_APP_KEY) return;
    userId = id;
    // Opened through a tunnel or another host (e.g. ngrok on a phone): reach Reverb on the same address
    // as the page (a proxy forwards /app and /apps to Reverb). Otherwise use the VITE_REVERB_* settings.
    const envHost = import.meta.env.VITE_REVERB_HOST;
    const sameOrigin = !!envHost && envHost !== window.location.hostname;
    const scheme = sameOrigin ? window.location.protocol.replace(':', '') : (import.meta.env.VITE_REVERB_SCHEME ?? 'https');
    const port = sameOrigin
        ? Number(window.location.port || (scheme === 'https' ? 443 : 80))
        : Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80));
    configureEcho({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: sameOrigin ? window.location.hostname : envHost || window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        // private channels: /broadcasting/auth with the web session; the CSRF token comes from the XSRF cookie,
        // so it stays valid after logging in without a full page load
        authorizer: (channel: { name: string }) => ({
            authorize: (socketId: string, callback: (error: Error | null, data: unknown) => void) => {
                fetch('/broadcasting/auth', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                    body: new URLSearchParams({ socket_id: socketId, channel_name: channel.name }),
                })
                    .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`auth ${r.status}`))))
                    .then((data) => callback(null, data))
                    .catch((e) => callback(e, null));
            },
        }),
    } as never);
    const e = echo<'reverb'>() as unknown as Echo<'reverb'>;
    window.Echo = e;
    const conn = e.connector.pusher.connection;
    echoState.value = conn.state;
    conn.bind('state_change', (s: { current: string }) => (echoState.value = s.current));
}

export function stopEcho(): void {
    if (window.Echo) {
        try {
            window.Echo.disconnect();
        } catch {
            /* already closed */
        }
    }
    window.Echo = undefined;
    userId = null;
    echoState.value = 'disconnected';
}

/** true while the websocket is up (otherwise pages fall back to polling) */
export const echoConnected = () => echoState.value === 'connected';
