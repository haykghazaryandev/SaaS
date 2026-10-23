import type { SocialCounts } from '@/types';
import type { Notice } from '@/types/social';
import { reactive } from 'vue';

/**
 * Header badges and the notification bell, shared by every page (module state, survives page visits).
 * Live: when window.Echo is connected (resources/js/lib/echo.ts) we listen on
 *   private-App.Models.User.{id}  .notification(...)   new notices
 *   private-user.{id}             .chat.updated        unread chat counters changed
 * otherwise we poll GET /notifications every 30 s.
 */
export const social = reactive({
    counts: { notifications: 0, messages: 0, friendRequests: 0 } as SocialCounts,
    items: [] as Notice[],
    loaded: false,
    toast: null as { text: string; link: string | null } | null,
});

type EchoLike = {
    private: (ch: string) => { notification: (cb: (n: Record<string, unknown>) => void) => unknown; listen: (ev: string, cb: () => void) => unknown };
    connector?: { pusher?: { connection?: { state?: string } } };
};

const POLL_MS = 30_000;
let liveFor: number | null = null;
let pollTimer: number | undefined;
let toastTimer: number | undefined;
let refreshTimer: number | undefined;
let attached: EchoLike | null = null;

function xsrf(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

function echo(): EchoLike | null {
    return ((window as unknown as { Echo?: EchoLike }).Echo as EchoLike) ?? null;
}

function echoConnected(): boolean {
    const e = echo();
    return !!e && e === attached && e.connector?.pusher?.connection?.state === 'connected';
}

export function setCounts(c: SocialCounts | null | undefined) {
    if (c) Object.assign(social.counts, c);
}

/** Load the bell list and fresh counts. */
export async function refresh(): Promise<void> {
    if (!liveFor) return;
    try {
        const res = await fetch(route('notifications.index'), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (res.status === 401 || res.status === 419) return stopLive();
        if (!res.ok) return;
        const data = (await res.json()) as { items: Notice[]; counts: SocialCounts };
        social.items = data.items;
        social.loaded = true;
        setCounts(data.counts);
    } catch {
        /* offline: try again on the next tick */
    }
}

/** A few events can come at once (a chat message to many people): refresh once. */
function refreshSoon() {
    clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(refresh, 400);
}

/** Mark one notice (or all of them) as read. */
export async function markRead(id?: string): Promise<void> {
    const now = new Date().toISOString();
    for (const n of social.items) if ((!id || n.id === id) && !n.read_at) n.read_at = now;
    social.counts.notifications = id ? Math.max(0, social.counts.notifications - 1) : 0;
    try {
        const res = await fetch(route('notifications.read'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify(id ? { id } : {}),
        });
        if (res.ok) setCounts(((await res.json()) as { counts: SocialCounts }).counts);
    } catch {
        /* the next refresh fixes the numbers */
    }
}

export function showToast(text: string, link: string | null = null) {
    social.toast = { text, link };
    clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => (social.toast = null), 6000);
}

async function onNotice(n: Record<string, unknown>) {
    // Laravel puts the notification class in "type". The broadcast text is in the user's saved language;
    // the bell list is rendered in the page language, so prefer that text once it is loaded.
    social.counts.notifications++;
    clearTimeout(refreshTimer);
    await refresh();
    const item = social.items.find((i) => i.id === n.id);
    const text = item?.text || (typeof n.text === 'string' ? n.text : '');
    if (text) showToast(text, item?.link ?? (typeof n.link === 'string' ? n.link : null));
}

/** Listen on the user's channels once per Echo connection (echo.ts makes a new one when the user changes). */
function attach() {
    const e = echo();
    if (!e || e === attached || !liveFor) return;
    attached = e;
    try {
        e.private(`App.Models.User.${liveFor}`).notification(onNotice);
        e.private(`user.${liveFor}`).listen('.chat.updated', refreshSoon);
    } catch {
        attached = null;
    }
}

/** Called by the layout with the logged-in user's id (null for guests). */
export function startLive(userId: number | null | undefined) {
    const id = userId ?? null;
    if (id === liveFor) return attach();
    stopLive();
    if (!id) return;
    liveFor = id;
    attach();
    pollTimer = window.setInterval(() => {
        attach();
        if (!echoConnected() && document.visibilityState !== 'hidden') refresh();
    }, POLL_MS);
}

export function stopLive() {
    clearInterval(pollTimer);
    liveFor = null;
    attached = null;
    social.items = [];
    social.loaded = false;
}
