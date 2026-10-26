// Telegram Mini App: Bookball opened inside Telegram (the bot's menu button, buttons in its messages, t.me links).
// app.blade.php loads Telegram's script only in that case; everything here is a no-op on the normal website.

export interface TgWebApp {
    initData: string;
    platform: string;
    colorScheme: 'light' | 'dark';
    ready(): void;
    expand(): void;
    close(): void;
    setHeaderColor(color: string): void;
    setBackgroundColor(color: string): void;
    disableVerticalSwipes?(): void;
    openLink(url: string): void;
    openTelegramLink(url: string): void;
    requestContact(callback: (shared: boolean) => void): void;
    BackButton: { show(): void; hide(): void; onClick(cb: () => void): void };
    onEvent?(event: string, cb: () => void): void;
    isExpanded?: boolean;
    HapticFeedback?: { impactOccurred(style: 'light' | 'medium' | 'heavy'): void };
    /** Telegram's own QR scanner (Bot API 6.4+): the callback gets each text; return true to close it */
    showScanQrPopup?(params: { text?: string }, callback: (text: string) => boolean | void): void;
    closeScanQrPopup?(): void;
}

/** The Mini App API when we run inside Telegram (with signed initData), otherwise null. */
export function tgApp(): TgWebApp | null {
    const w = (window as unknown as { Telegram?: { WebApp?: TgWebApp } }).Telegram?.WebApp;
    return w && w.initData ? w : null;
}

export const inTelegram = () => tgApp() !== null;
