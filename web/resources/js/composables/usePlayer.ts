import { trans } from '@/lib/i18n';

// Helpers for the player pages: dates (local calendar days as "2026-10-21"), durations, field colours.

/** field size colours (tokens in bookball.css): small 5×5 = green, big 11×11 = lime */
export const SIZE_COLOR: Record<string, string> = { small: 'var(--field-small)', big: 'var(--field-big)' };

/** grass pitches are drawn darker */
export const pitchColor = (surface?: string | null) => (surface === 'grass' ? '#2b7d3f' : undefined);

const parts = (iso: string) => iso.split('-').map(Number);

/** weekday 0 (Sunday) … 6 of a calendar date */
export function weekday(iso: string): number {
    const [y, m, d] = parts(iso);
    return new Date(Date.UTC(y, m - 1, d)).getUTCDay();
}
export const wdName = (i: number) => trans('common.weekday_names_short').split(',')[i] ?? '';
export const monthName = (i: number) => trans('common.month_names_short').split(',')[i] ?? '';

/** "21 Oct" */
export function dateShort(iso: string): string {
    const [, m, d] = parts(iso);
    return `${d} ${monthName(m - 1)}`;
}
/** "Wed, 21 Oct" */
export function dateLong(iso: string): string {
    return `${wdName(weekday(iso))}, ${dateShort(iso)}`;
}
/** "Today" / "Tomorrow" / "Wed, 21 Oct" */
export function dateRel(iso: string, todayIso: string): string {
    const diff = Math.round((Date.UTC(...(parts(iso) as [number, number, number])) - Date.UTC(...(parts(todayIso) as [number, number, number]))) / 864e5);
    if (diff === 0) return trans('common.today');
    if (diff === 1) return trans('common.tomorrow');
    return dateLong(iso);
}
/** 90 -> "90 min", 120 -> "2 h" */
export function dur(m: number): string {
    return m % 60 ? `${m} ${trans('common.minute_short')}` : `${m / 60} ${trans('common.hour_short')}`;
}
/** "19:30" -> 19.5 */
export function toHours(t: string): number {
    const [h, m] = t.split(':').map(Number);
    return h + m / 60;
}
/** days between two calendar dates (b − a) */
export function dayDiff(a: string, b: string): number {
    return Math.round((Date.UTC(...(parts(b) as [number, number, number])) - Date.UTC(...(parts(a) as [number, number, number]))) / 864e5);
}
/** "Today 19:00" / "Tomorrow 19:00" / "Fri 19:00" (within a week) / "Fri, 31 Oct 19:00"; inline = "today" / "tomorrow" in lower case (inside a sentence) */
export function nextLabel(iso: string, todayIso: string, time: string, inline = false): string {
    const d = dayDiff(todayIso, iso);
    const rel = (k: string) => (inline ? trans(k).toLocaleLowerCase() : trans(k));
    const day = d === 0 ? rel('common.today') : d === 1 ? rel('common.tomorrow') : d < 7 ? wdName(weekday(iso)) : dateLong(iso);
    return `${day} ${time}`;
}
/** "today" as a calendar date in Yerevan */
export const yerevanToday = (ms = Date.now()) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Yerevan' }).format(new Date(ms));
/** Google Maps link for a place (coordinates, else the address) */
export function mapsUrl(v: { lat: number | null; lng: number | null; address?: string | null; name?: string }): string | null {
    if (v.lat != null && v.lng != null) return `https://maps.google.com/?q=${v.lat},${v.lng}`;
    if (v.address) return `https://maps.google.com/?q=${encodeURIComponent(`${v.name ?? ''} ${v.address}, Yerevan`.trim())}`;
    return null;
}
/** directions to a place: Google Maps and Yandex Maps (coordinates; Google falls back to the address) */
export function directionLinks(v: { lat: number | null; lng: number | null; address?: string | null; name?: string }): { google: string | null; yandex: string | null } {
    if (v.lat != null && v.lng != null) {
        return {
            google: `https://www.google.com/maps/dir/?api=1&destination=${v.lat},${v.lng}`,
            yandex: `https://yandex.com/maps/?rtext=~${v.lat},${v.lng}&rtt=auto`,
        };
    }
    const q = v.address ? encodeURIComponent(`${v.name ?? ''} ${v.address}, Yerevan`.trim()) : null;
    return { google: q ? `https://www.google.com/maps/dir/?api=1&destination=${q}` : null, yandex: null };
}
