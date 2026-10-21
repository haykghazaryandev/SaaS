export interface AuthUser {
    id: number;
    name: string | null;
    avatar?: string | null;
    phone: string;
    username: string | null;
    registered: boolean;
    telegram: boolean;
    isAdmin: boolean;
    isOwner: boolean;
}

export interface SharedData {
    locale: 'hy' | 'ru' | 'en';
    auth: { user: AuthUser | null };
    flash: { success: string | null; error: string | null };
    devCode: string | null;
    /** header badges (logged-in users only) */
    counts?: SocialCounts | null;
    [key: string]: unknown;
}

export interface SocialCounts {
    notifications: number;
    messages: number;
    friendRequests: number;
    /** game invites I have not answered */
    invites: number;
    /** my booking requests waiting for the place */
    pendingBookings: number;
}
