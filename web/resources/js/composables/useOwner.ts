import type { FormDataConvertible } from '@inertiajs/core';
import { hhmm } from '@/composables/useShared';
import { router, usePage } from '@inertiajs/vue3';
import { trans } from '@/lib/i18n';
import { computed, reactive } from 'vue';

// ---------- types (owner web) ----------
export interface OwnerField {
    id: number;
    size: 'small' | 'big' | 'table';
    sport?: string;
    format: string;
    label: string;
    /** "Table 3" or "5×5": tabs and chips */
    short?: string;
    durations: number[];
    default_duration: number;
    active: boolean;
}

export interface OwnerShared {
    venue: { id: number; name: string; slug: string };
    role: 'owner' | 'staff';
    venues: { id: number; name: string }[];
    pending: number;
    fields: OwnerField[];
    hours: ([number, number] | null)[]; // by weekday, 0 = Sunday
    today: string;
    nowHour: number;
}

/** Visual kind of booking (BookingPresenter::kind): colors come from the --st-* tokens. */
export type Kind = 'pending' | 'accepted' | 'owner' | 'noshow' | 'expired' | 'off';

export interface BookingRow {
    id: number;
    code: string;
    field_id: number;
    date: string;
    start: number;
    end: number;
    minutes: number;
    status: string;
    source: string;
    kind: Kind;
    name: string;
    team: string | null;
    phone: string | null;
    tel: string | null;
    price: number;
    note_player: string | null;
    note_owner: string | null;
    weekly: boolean;
    started: boolean;
    past: boolean;
    arrived?: boolean;
    late_minutes?: number | null;
    can_arrive?: boolean;
    can_no_show?: boolean;
    user: { id: number; name: string | null; avatar?: string | null; no_shows: number } | null;
    sent_ago: string | null;
}

/** A request on Today, with the other open requests it clashes with. */
export interface RequestRow extends BookingRow {
    conflicts: { id: number; field_id: number; name: string; start: number }[];
}

export interface BookingDetail extends BookingRow {
    field: string;
    decline_reason: string | null;
    cancel_reason: string | null;
    cancelled_by: string | null;
    events: { type: string; label: string; who: string; at: string; details: string[] }[];
}

export interface FormOpts {
    booking?: BookingDetail;
    field_id?: number;
    date?: string;
    start?: number;
}

/** The owner props shared by EnsureVenueStaff. */
export function useOwner() {
    const page = usePage();
    return computed(() => page.props.owner as OwnerShared);
}

// ---------- sheets (one host in OwnerLayout; any page can open them) ----------
export const sheets = reactive({
    detail: null as number | null,
    form: null as FormOpts | null,
    reason: null as { kind: 'decline' | 'cancel'; id: number; who?: string } | null,
    toast: null as string | null,
});

let toastTimer: number | undefined;
export function toastError(msg: string) {
    sheets.toast = msg;
    clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => (sheets.toast = null), 4000);
}

export const openBooking = (id: number) => (sheets.detail = id);
export const openForm = (opts: FormOpts = {}) => {
    sheets.detail = null;
    sheets.form = opts;
};
export const askDecline = (id: number, who?: string) => {
    sheets.detail = null;
    sheets.reason = { kind: 'decline', id, who };
};
export const askCancel = (id: number) => {
    sheets.detail = null;
    sheets.reason = { kind: 'cancel', id };
};

/** POST an action (accept, decline, cancel, no-show); a broken rule comes back as errors.booking. */
export function act(name: 'accept' | 'decline' | 'cancel' | 'no-show' | 'arrived', id: number, data: Record<string, FormDataConvertible> = {}, done?: () => void) {
    router.post(route(`owner.bookings.${name}`, id), data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => done?.(),
        onError: (errors) => toastError(errors.booking || Object.values(errors)[0] || trans('owner.error.generic')),
    });
}

// ---------- accept / decline with "Undo" ----------
// The answer is kept on this device for 5 seconds while a toast offers "Undo"; only then it is sent (fetch, JSON).
// Nothing reaches the player until then, so Undo never has to take back a Telegram message. The answer is sent
// at once when another one is given, or when the page is hidden or closed (keepalive), so it is never lost.
export const UNDO_MS = 5000;
interface Decision {
    id: number;
    kind: 'accept' | 'decline';
    reason: string | null;
    who: string;
    until: number;
    timer: number;
}
export const decisions = reactive({
    current: null as Decision | null,
    /** bookings answered on this device but not yet reloaded from the server: id -> new state */
    hidden: {} as Record<number, 'accepted' | 'declined'>,
});

function xsrf(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** "Narek · 20:30" for the toast. */
export const whoOf = (b: { team?: string | null; name: string; start: number }) => `${b.team || b.name} · ${hhmm(b.start)}`;

export function decide(kind: 'accept' | 'decline', id: number, opts: { reason?: string | null; who?: string } = {}) {
    if (decisions.current?.id === id) return;
    if (decisions.current) commit();
    sheets.detail = null;
    decisions.hidden[id] = kind === 'accept' ? 'accepted' : 'declined';
    decisions.current = {
        id,
        kind,
        reason: opts.reason ?? null,
        who: opts.who ?? '',
        until: Date.now() + UNDO_MS,
        timer: window.setTimeout(commit, UNDO_MS),
    };
}

export function undo() {
    const d = decisions.current;
    if (!d) return;
    clearTimeout(d.timer);
    delete decisions.hidden[d.id];
    decisions.current = null;
}

/** Sends the waiting answer now. */
export async function commit() {
    const d = decisions.current;
    if (!d) return;
    clearTimeout(d.timer);
    decisions.current = null;
    try {
        const r = await fetch(route(`owner.bookings.${d.kind}`, d.id), {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify(d.kind === 'decline' ? { reason: d.reason } : {}),
        });
        if (!r.ok) {
            let msg = trans('owner.error.generic');
            try {
                const errs = ((await r.json()).errors ?? {}) as Record<string, string[]>;
                msg = errs.booking?.[0] || Object.values(errs)[0]?.[0] || msg;
            } catch {
                /* not JSON */
            }
            toastError(msg);
        }
    } catch {
        toastError(trans('owner.error.generic'));
    }
    // refresh the owner page (without cancelling a visit in progress), then forget the local state
    const component = String(usePageSafe()?.component ?? '');
    if (component.startsWith('owner/')) {
        router.reload({ async: true, onFinish: () => delete decisions.hidden[d.id] } as Parameters<typeof router.reload>[0]);
    } else {
        delete decisions.hidden[d.id];
    }
}
function usePageSafe() {
    try {
        return usePage();
    } catch {
        return null;
    }
}
if (typeof window !== 'undefined') {
    window.addEventListener('pagehide', () => commit());
    document.addEventListener('visibilitychange', () => document.visibilityState === 'hidden' && commit());
}

/** Accept with Undo (the row gives the toast its "name · time"). */
export const accept = (id: number, row?: { team?: string | null; name: string; start: number }) =>
    decide('accept', id, { who: row ? whoOf(row) : '' });

// ---------- formatting ----------
const LOCALES: Record<string, string> = { hy: 'hy-AM', ru: 'ru-RU', en: 'en-GB' };
function loc(): string {
    const l = (usePage().props.locale as string) || document.documentElement.lang || 'hy';
    return LOCALES[l] || 'en-GB';
}
const asDate = (iso: string) => new Date(`${iso}T12:00:00Z`);

// Chrome often has no Armenian date names in Intl, so Armenian is formatted here
const HY = {
    wdLong: ['կիրակի', 'երկուշաբթի', 'երեքշաբթի', 'չորեքշաբթի', 'հինգշաբթի', 'ուրբաթ', 'շաբաթ'],
    wdShort: ['կիր', 'երկ', 'երք', 'չրք', 'հնգ', 'ուր', 'շբթ'],
    monLong: [
        'հունվարի',
        'փետրվարի',
        'մարտի',
        'ապրիլի',
        'մայիսի',
        'հունիսի',
        'հուլիսի',
        'օգոստոսի',
        'սեպտեմբերի',
        'հոկտեմբերի',
        'նոյեմբերի',
        'դեկտեմբերի',
    ],
    monShort: ['հնվ', 'փտվ', 'մրտ', 'ապր', 'մյս', 'հնս', 'հլս', 'օգս', 'սեպ', 'հոկ', 'նոյ', 'դեկ'],
};
function fdateHy(d: Date, o: Intl.DateTimeFormatOptions): string {
    const wd = o.weekday ? (o.weekday === 'long' ? HY.wdLong : HY.wdShort)[d.getUTCDay()] : '';
    const mon = o.month ? (o.month === 'long' ? HY.monLong : HY.monShort)[d.getUTCMonth()] : '';
    const dm = [o.day ? String(d.getUTCDate()) : '', mon].filter(Boolean).join(' ');
    return [wd, dm].filter(Boolean).join(', ');
}

/** "2026-10-21" with Intl options, in the page language (dates are local days, so UTC noon is safe). */
export function fdate(iso: string, opts: Intl.DateTimeFormatOptions): string {
    const l = loc();
    if (l === 'hy-AM') return fdateHy(asDate(iso), opts);
    return new Intl.DateTimeFormat(l, { timeZone: 'UTC', ...opts }).format(asDate(iso));
}
export const weekdayOf = (iso: string) => asDate(iso).getUTCDay();
export function addDays(iso: string, n: number): string {
    const d = asDate(iso);
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
}
export function daysBetween(a: string, b: string): number {
    return Math.round((asDate(b).getTime() - asDate(a).getTime()) / 864e5);
}
/** Today / Tomorrow / Yesterday, otherwise "Wed 21 Oct". */
export function relDay(iso: string, today: string): string {
    const d = daysBetween(today, iso);
    if (d === 0) return trans('common.today');
    if (d === 1) return trans('common.tomorrow');
    if (d === -1) return trans('common.yesterday');
    return fdate(iso, { weekday: 'short', day: 'numeric', month: 'short' });
}
export const longDate = (iso: string) => fdate(iso, { weekday: 'long', day: 'numeric', month: 'long' });

/** 90 -> "1.5 h" */
export function dur(minutes: number): string {
    const h = minutes / 60;
    return trans('owner.duration_hours', { h: String(Number.isInteger(h) ? h : h.toFixed(1)) });
}

/** Pill class and label of a booking. */
export function pillOf(b: { kind: Kind; status: string }): { cls: string; label: string } {
    const cls = { pending: 'warn', accepted: 'green', owner: 'manual', noshow: 'hot', expired: 'off', off: 'off' }[b.kind];
    const label = b.kind === 'owner' ? trans('owner.status.owner') : b.kind === 'expired' ? trans('owner.status.expired') : trans(`owner.status.${b.status}`);
    return { cls, label };
}

/** "5×5" field dot class. */
export const dotOf = (size: string) => (size === 'small' ? 's' : size === 'table' ? 't' : 'b');
