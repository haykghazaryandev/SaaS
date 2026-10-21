// Props of the games pages (see App\Http\Presenters\PlayerGames).

export interface GamePerson {
    id: number;
    name: string;
    short: string;
    initials: string;
    level: string | null;
}

export interface GamePlayerRow extends GamePerson {
    status: string;
    creator: boolean;
    me: boolean;
}

export interface GameWindow {
    date: string;
    from: string | null;
    to: string | null;
}

export interface GameBooking {
    id: number;
    status: string;
    code: string;
    price: number;
    venue: { slug: string; name: string; district: string };
    field: { size: string; format: string };
    date: string;
    start: string;
    end: string;
}

export interface GameCard {
    id: number;
    format: string;
    perSide: number;
    total: number;
    minToOpen: number;
    in: number;
    status: 'forming' | 'open' | 'full' | 'booked' | 'played' | 'cancelled' | 'archived';
    level: string | null;
    district: string | null;
    venue: { slug: string; name: string; district: string } | null;
    window: GameWindow | null;
    note: string | null;
    creator: GamePerson;
    avatars: { initials: string; name: string | null }[];
    booking: GameBooking | null;
    me: string | null;
    mine: boolean;
}

export interface BookPlace {
    id: number;
    slug: string;
    name: string;
    district: string;
    fields: { id: number; size: string; format: string; durations: number[]; defaultDuration: number }[];
}
