import type { GameCard, GameWindow } from '@/types/games';
import { trans } from '@/lib/i18n';
import { dateRel, dateShort, wdName, weekday } from './usePlayer';

// Helpers for the games pages.

/** today in Yerevan as "2026-10-21" */
export const todayYerevan = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Yerevan' }).format(new Date());

/** "Tomorrow · 19:00–22:00", "Fri, 23 Oct", or "Time to agree" */
export function whenText(w: GameWindow | null): string {
    if (!w) return trans('games.card.any_time');
    const day = dateRel(w.date, todayYerevan());
    if (!w.from) return day;
    return `${day} · ${w.from}${w.to ? `–${w.to}` : ''}`;
}

/** where the game is: preferred place, else district, else "Anywhere in Yerevan" */
export function whereText(g: Pick<GameCard, 'venue' | 'district'>): string {
    if (g.venue) return g.venue.name;
    if (g.district) return trans(`common.districts.${g.district}`);
    return trans('games.card.anywhere');
}

/** status-pill colour of each game status (G1 tokens: waiting = pending, going on = confirmed, over = off) */
export const STATUS_LOOK: Record<string, string> = {
    forming: 'pending',
    open: 'confirmed',
    full: 'confirmed',
    booked: 'confirmed',
    played: 'off',
    cancelled: 'off',
    archived: 'off',
};

/** field size class of a game: up to 6 per side = small (5×5 field), else big */
export const sizeOf = (perSide: number) => (perSide >= 7 ? 'big' : 'small');

/** "Thu 21:00" / "Today 19:00" / "Thu" (short, for the chat bar and cards) */
export function whenShort(w: { date: string; from: string | null } | null | undefined): string {
    if (!w) return '';
    const today = todayYerevan();
    const diff = Math.round((Date.parse(w.date) - Date.parse(today)) / 864e5);
    const day = diff <= 1 ? dateRel(w.date, today) : diff <= 6 ? wdName(weekday(w.date)) : dateShort(w.date);
    return w.from ? `${day} ${w.from}` : day;
}

/** does the Messages page exist yet (built by another part)? */
export function hasRoute(name: string): boolean {
    try {
        return route().has(name);
    } catch {
        return false;
    }
}
