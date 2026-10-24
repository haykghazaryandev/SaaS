// Props of the player pages (see App\Http\Presenters\PlayerVenues).

export interface DayInfo {
    date: string; // "2026-10-21" (local)
    k: number; // 0 = today
    wd: number; // 0 = Sunday
    day: number;
    month: number; // 0 = January
    weekend: boolean;
}

export interface FieldInfo {
    id: number;
    size: 'small' | 'big';
    format: string;
    surface: string;
    indoor: boolean;
    players: number;
    durations: number[];
    defaultDuration: number;
    price: { min: number; max: number };
}

export interface VenueCard {
    id: number;
    slug: string;
    name: string;
    avatar?: string | null;
    district: string;
    address: string | null;
    rating: number | null;
    reviews: number;
    hours: [number, number] | null;
    from: number;
    fields: (FieldInfo & { starts: number[]; more: number; next: NextFree | null })[];
    allFields: { size: string; indoor: boolean; surface: string }[];
    free: boolean;
}

/** the first free start on a later day */
export interface NextFree {
    date: string;
    start: number;
}

export interface SlotsPayload {
    date: string;
    hours: [number, number] | null;
    fields: {
        id: number;
        /** free start times per duration (minutes) */
        starts: Record<string, number[]>;
        /** start times inside opening hours that are taken, per duration */
        taken: Record<string, number[]>;
        /** durations with nothing free this day: the next free start */
        next: Record<string, NextFree>;
        rates: [number, number][];
    }[];
}

export interface PlayerBooking {
    id: number;
    code: string;
    status: 'pending' | 'accepted' | 'declined' | 'cancelled' | 'expired' | 'completed' | 'no_show';
    venue: { slug: string; name: string; district: string; address: string | null; lat: number | null; lng: number | null; phone: string | null };
    field: { id: number; size: string; format: string; indoor: boolean; surface: string };
    date: string;
    start: string;
    end: string;
    startsAt: string;
    endsAt: string;
    minutes: number;
    price: number;
    teamName: string | null;
    note: string | null;
    declineReason: string | null;
    cancelReason: string | null;
    cancelledBy: string | null;
    canCancel: boolean;
    freeCancelHours: number;
    lateCancel: boolean;
    // My bookings only: the team, "running late", arrival
    role?: 'booker' | 'member';
    booker?: { id: number | null; name: string | null; avatar: string | null } | null;
    team?: { id: number; status: 'joined' | 'invited'; user: { id: number; name: string; avatar: string | null } | null; phone: string | null }[];
    teamMax?: number;
    inviteUrl?: string | null;
    canLate?: boolean;
    lateOptions?: number[];
    lateMinutes?: number | null;
    arrived?: boolean;
}
