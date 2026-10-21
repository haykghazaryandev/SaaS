import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, reactive } from 'vue';

// ---------- types (owner web) ----------
export interface OwnerField {
    id: number;
    size: 'small' | 'big';
    format: string;
    label: string;
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

export type Kind = 'pending' | 'accepted' | 'owner' | 'noshow' | 'off';

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
    user: { id: number; name: string | null; no_shows: number } | null;
    sent_ago: string | null;
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
    reason: null as { kind: 'decline' | 'cancel'; id: number } | null,
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
export const askDecline = (id: number) => {
    sheets.detail = null;
    sheets.reason = { kind: 'decline', id };
};
export const askCancel = (id: number) => {
    sheets.detail = null;
    sheets.reason = { kind: 'cancel', id };
};

/** POST an action (accept, decline, cancel, no-show); a broken rule comes back as errors.booking. */
export function act(name: 'accept' | 'decline' | 'cancel' | 'no-show', id: number, data: Record<string, unknown> = {}, done?: () => void) {
    router.post(route(`owner.bookings.${name}`, id), data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => done?.(),
        onError: (errors) => toastError(errors.booking || Object.values(errors)[0] || trans('owner.err.generic')),
    });
}
export const accept = (id: number) => act('accept', id);

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
    if (d === 0) return trans('owner.today');
    if (d === 1) return trans('owner.tomorrow');
    if (d === -1) return trans('owner.yesterday');
    return fdate(iso, { weekday: 'short', day: 'numeric', month: 'short' });
}
export const longDate = (iso: string) => fdate(iso, { weekday: 'long', day: 'numeric', month: 'long' });

/** 90 -> "1.5 h" */
export function dur(minutes: number): string {
    const h = minutes / 60;
    return trans('owner.dur', { h: String(Number.isInteger(h) ? h : h.toFixed(1)) });
}

/** Pill class and label of a booking. */
export function pillOf(b: { kind: Kind; status: string }): { cls: string; label: string } {
    const cls = { pending: 'warn', accepted: 'green', owner: 'blue', noshow: 'hot', off: '' }[b.kind];
    const label = b.kind === 'owner' ? trans('owner.st.owner') : trans(`owner.st.${b.status}`);
    return { cls, label };
}

/** "5×5" field dot class. */
export const dotOf = (size: string) => (size === 'small' ? 's' : 'b');
