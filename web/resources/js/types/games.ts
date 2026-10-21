// Props of the games pages (see App\Http\Presenters\PlayerGames).

export interface GamePerson {
    id: number;
    name: string;
    avatar?: string | null;
    short: string;
    initials: string;
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
    district: string | null;
    venue: { slug: string; name: string; district: string } | null;
    window: GameWindow | null;
    note: string | null;
    creator: GamePerson;
    avatars: { id?: number; initials: string; name: string | null }[];
    booking: GameBooking | null;
    me: string | null;
    mine: boolean;
    /** who invited me (only while my invite is open) */
    invitedBy?: GamePerson | null;
}

export interface BookPlace {
    id: number;
    slug: string;
    name: string;
    avatar?: string | null;
    district: string;
    fields: { id: number; size: string; format: string; durations: number[]; defaultDuration: number }[];
}
