import type { GameCard, GameWindow } from '@/types/games';
import { trans } from 'laravel-vue-i18n';
import { dateRel } from './usePlayer';

// Helpers for the games pages.

/** today in Yerevan as "2026-10-21" */
export const todayYerevan = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Yerevan' }).format(new Date());

/** "Tomorrow · 19:00–22:00", "Fri, 23 Oct", or "Time to agree" */
export function whenText(w: GameWindow | null): string {
    if (!w) return trans('games.card.anyTime');
    const day = dateRel(w.date, todayYerevan());
    if (!w.from) return day;
    return `${day} · ${w.from}${w.to ? `–${w.to}` : ''}`;
}

/** where the game is: preferred place, else district, else "Anywhere in Yerevan" */
export function whereText(g: Pick<GameCard, 'venue' | 'district'>): string {
    if (g.venue) return g.venue.name;
    if (g.district) return trans(`player.district.${g.district}`);
    return trans('games.card.anywhere');
}

/** colour of each game status pill */
export const STATUS_LOOK: Record<string, [string, string]> = {
    forming: ['warn', 'users'],
    open: ['green', 'ball'],
    full: ['lime', 'check'],
    booked: ['blue', 'calendar'],
    played: ['', 'trophy'],
    cancelled: ['hot', 'x'],
    archived: ['', 'clock'],
};

/** does the Messages page exist yet (built by another part)? */
export function hasRoute(name: string): boolean {
    try {
        return route().has(name);
    } catch {
        return false;
    }
}
