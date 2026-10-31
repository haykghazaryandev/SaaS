export interface AuthUser {
    id: number;
    name: string | null;
    avatar?: string | null;
    phone: string;
    username: string | null;
    registered: boolean;
    telegram: boolean;
    /** a phone confirmed with Telegram: needed to book */
    phoneConfirmed?: boolean;
    /** the account's email (null: none) and whether it is confirmed (a reminder until then) */
    email?: string | null;
    emailConfirmed?: boolean;
    isAdmin: boolean;
    isOwner: boolean;
    playerMode?: boolean;
    /** a sound for new notices and chat messages (Profile → Notifications) */
    sound?: boolean;
}

export interface SharedData {
    locale: 'hy' | 'ru' | 'en';
    auth: { user: AuthUser | null };
    flash: { success: string | null; error: string | null };
    devCode: string | null;
    /** header badges (logged-in users only) */
    counts?: SocialCounts | null;
    /** the sport chosen on the home page (null on "What do you play?") */
    sport?: string | null;
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
    /** tournaments I registered for */
    tournaments?: number;
}
