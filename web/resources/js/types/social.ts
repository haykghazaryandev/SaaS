// Props of the friends / profile pages (see App\Http\Presenters\PlayerCards).

export type Relation = 'self' | 'friends' | 'sent' | 'received' | 'blocked' | 'none';

export interface PlayerCard {
    id: number;
    name: string;
    avatar?: string | null;
    username: string | null;
    position: string | null;
    district: string | null;
    url: string;
    relation: Relation;
    games?: number; // "played with you": games together
}

export interface Notice {
    id: string;
    type: string;
    text: string;
    link: string | null;
    /** the person the notice is about (friend requests, invites…), for the avatar */
    name?: string | null;
    read_at: string | null;
    created_at: string;
}
