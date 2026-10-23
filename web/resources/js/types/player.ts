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
    district: string;
    address: string | null;
    rating: number | null;
    reviews: number;
    hours: [number, number] | null;
    from: number;
    fields: (FieldInfo & { starts: number[]; more: number })[];
    allFields: { size: string; indoor: boolean; surface: string }[];
    free: boolean;
}

export interface SlotsPayload {
    date: string;
    hours: [number, number] | null;
    fields: { id: number; starts: Record<string, number[]>; rates: [number, number][] }[];
}

export interface PlayerBooking {
    id: number;
    code: string;
    status: 'pending' | 'accepted' | 'declined' | 'cancelled' | 'expired' | 'completed' | 'no_show';
    venue: { slug: string; name: string; district: string; address: string | null };
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
}
