import { dateLong, dateShort, wdName, weekday } from '@/composables/usePlayer';
import { xsrf } from '@/lib/echo';
import { trans } from '@/lib/i18n';

// Chat helpers shared by the chat list and the chat screen. Shapes match App\Domain\Chat\ChatPresenter.

export interface ChatUser {
    id: number;
    name: string | null;
    username: string | null;
    avatar?: string | null;
}
export interface ChatMessage {
    id: number;
    conversation_id: number;
    type: 'text' | 'system' | 'booking';
    body: string | null;
    meta: Record<string, string | number> | null;
    deleted: boolean;
    edited: boolean;
    reply_to_id: number | null;
    reply: { id: number; type: string; body: string | null; meta: Record<string, string | number> | null; deleted: boolean; user: ChatUser | null } | null;
    user: ChatUser | null;
    created_at: string;
}
export interface ChatItem {
    id: number;
    type: 'direct' | 'game';
    archived: boolean;
    // direct chats; online = seen in the last 2 minutes (User::isActiveNow), lastSeen an ISO time
    other: { id: number; name: string; username: string | null; avatar?: string | null; online?: boolean; lastSeen?: string | null } | null;
    game: {
        id: number;
        format: string;
        status: string;
        players: number;
        total: number;
        url: string | null;
        // pinned game bar (see ChatController::item)
        window?: { date: string; from: string | null; to: string | null } | null;
        place?: string | null;
        district?: string | null;
        booking?: { status: string; date: string; start: string; venue: { name: string } } | null;
    } | null;
    last: ChatMessage | null;
    unread: number;
    at: string | null;
}

/** a system line ("Davit joined"): body is the key (chat.sys.joined), meta its parameters */
export function sysText(body: string | null, meta: Record<string, string | number> | null): string {
    if (!body) return '';
    const params: Record<string, string> = {};
    for (const [k, v] of Object.entries(meta ?? {})) params[k] = String(v);
    return trans(body, params);
}

export const userName = (u: ChatUser | null | undefined) => u?.name || (u?.username ? '@' + u.username : trans('messages.player'));

export const initials = (n: string | null | undefined) =>
    (n || '?')
        .replace(/^@/, '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase();

export function chatTitle(c: ChatItem): string {
    if (c.type === 'game' && c.game) return trans('messages.game', { f: c.game.format });
    return c.other?.name ?? trans('messages.direct');
}

// ---------- dates (Yerevan time) ----------
const TZ = 'Asia/Yerevan';
const dayFmt = new Intl.DateTimeFormat('en-CA', { timeZone: TZ });
const timeFmt = new Intl.DateTimeFormat('en-GB', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });

/** "2026-10-21" in Yerevan */
export const dayKey = (iso: string | Date) => dayFmt.format(typeof iso === 'string' ? new Date(iso) : iso);
/** "21:05" in Yerevan */
export const clock = (iso: string) => timeFmt.format(new Date(iso));

/** "Today" / "Yesterday" / "Wed, 21 Oct" */
export function dayLabel(key: string): string {
    const today = dayKey(new Date());
    if (key === today) return trans('common.today');
    if (key === dayKey(new Date(Date.now() - 864e5))) return trans('common.yesterday');
    return dateLong(key);
}
/** chat list: "21:05" today, the weekday ("Mon") within the last 6 days, else "21 Oct" */
export function shortWhen(iso: string | null): string {
    if (!iso) return '';
    const key = dayKey(iso);
    if (key === dayKey(new Date())) return clock(iso);
    if (key > dayKey(new Date(Date.now() - 6 * 864e5))) return wdName(weekday(key));
    return dateShort(key);
}

/** chat header: "online" / "last seen today at 14:20" / "last seen yesterday at 21:05" / "last seen 12.10" (Yerevan time) */
export function lastSeenText(online: boolean | undefined, iso: string | null | undefined): string {
    if (online) return trans('messages.online');
    if (!iso) return '';
    const key = dayKey(iso);
    if (key === dayKey(new Date())) return trans('messages.seen_today', { time: clock(iso) });
    if (key === dayKey(new Date(Date.now() - 864e5))) return trans('messages.seen_yesterday', { time: clock(iso) });
    const [y, m, d] = key.split('-');
    // another year: 12.10.2025
    return trans('messages.seen_date', { date: y === dayKey(new Date()).slice(0, 4) ? `${d}.${m}` : `${d}.${m}.${y}` });
}

// ---------- JSON calls (session cookie + CSRF from the XSRF cookie) ----------
export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
    ) {
        super(message);
    }
}
export async function api<T>(method: string, url: string, body?: unknown): Promise<T> {
    let r: Response;
    try {
        r = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    } catch {
        throw new ApiError(trans('messages.error'), 0);
    }
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new ApiError(data.error || data.message || trans('messages.error'), r.status);
    return data as T;
}
