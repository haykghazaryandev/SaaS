import { trans } from 'laravel-vue-i18n';

// Helpers for the player pages: dates (local calendar days as "2026-10-21"), durations, field colours.

/** field size colours: small 5×5 = green, big 11×11 = lime */
export const SIZE_COLOR: Record<string, string> = { small: '#5ef0a8', big: '#c8ff3d' };

/** grass pitches are drawn darker */
export const pitchColor = (surface?: string | null) => (surface === 'grass' ? '#2b7d3f' : undefined);

const parts = (iso: string) => iso.split('-').map(Number);

/** weekday 0 (Sunday) … 6 of a calendar date */
export function weekday(iso: string): number {
    const [y, m, d] = parts(iso);
    return new Date(Date.UTC(y, m - 1, d)).getUTCDay();
}
export const wdName = (i: number) => trans('player.wd').split(',')[i] ?? '';
export const monthName = (i: number) => trans('player.months').split(',')[i] ?? '';

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
    if (diff === 0) return trans('player.today');
    if (diff === 1) return trans('player.tomorrow');
    return dateLong(iso);
}
/** 90 -> "90 min", 120 -> "2 h" */
export function dur(m: number): string {
    return m % 60 ? `${m} ${trans('player.min')}` : `${m / 60} ${trans('player.h')}`;
}
/** "19:30" -> 19.5 */
export function toHours(t: string): number {
    const [h, m] = t.split(':').map(Number);
    return h + m / 60;
}
