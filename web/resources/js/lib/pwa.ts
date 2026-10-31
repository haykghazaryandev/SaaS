// Installable web app ("Add to Home Screen"): app icon + full screen, the cheap step before the mobile app.
// The manifest and the icons are linked in app.blade.php; Android (Chrome) also wants a service worker before it
// offers the install, so public/sw.js is registered here. It caches nothing (see the file). iOS needs no worker.
import { reactive } from 'vue';
import { inTelegram } from './telegram';

/** Chrome / Edge / Android: the event behind their "Install app" prompt (not in TypeScript's DOM types). */
export interface BeforeInstallPromptEvent extends Event {
    readonly platforms: string[];
    readonly userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
    prompt(): Promise<void>;
}

/** Opened from the home screen icon (full screen), not in a browser tab. */
export const standalone = () =>
    matchMedia('(display-mode: standalone)').matches || (navigator as unknown as { standalone?: boolean }).standalone === true;

// iPhone / iPad Safari: no prompt API, the steps are shown instead. iPadOS reports itself as a Mac, so a touch "Mac" is an iPad.
// Other iOS browsers (Chrome CriOS, Firefox FxiOS…) can add to the home screen only since iOS 16.4 and differently: Safari only.
function iosSafari(): boolean {
    const ua = navigator.userAgent;
    const ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    return ios && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS|YaBrowser|GSA\//.test(ua);
}

let deferred: BeforeInstallPromptEvent | null = null;

export const pwa = reactive({
    /** the browser's own install prompt is ready (Chrome / Edge / Android) */
    canInstall: false,
    /** iPhone / iPad Safari, not installed yet: we show the Share → Add to Home Screen steps */
    isIos: false,
    /** already installed (opened from the icon, or just installed) */
    installed: false,
    /** the iOS steps sheet is open (one sheet, mounted by the layouts) */
    iosSheet: false,
});

/** Whether to show any install entry (menu item, banner). */
export const installable = () => !pwa.installed && (pwa.canInstall || pwa.isIos);

export function installPwa() {
    if (typeof window === 'undefined' || inTelegram()) return; // inside Telegram we are already an app
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').catch(() => {
                /* http://, private mode…: the site simply stays a site */
            });
        });
    }

    pwa.installed = standalone();
    if (pwa.installed) return;
    pwa.isIos = iosSafari();
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault(); // no mini-infobar: our button opens the prompt when the person asks
        deferred = e as BeforeInstallPromptEvent;
        pwa.canInstall = true;
    });
    window.addEventListener('appinstalled', () => {
        deferred = null;
        pwa.canInstall = false;
        pwa.installed = true;
    });
}

/** "Install app": the browser's prompt, or the steps on iOS. */
export async function promptInstall() {
    if (deferred) {
        const e = deferred;
        deferred = null; // a prompt can be shown only once
        pwa.canInstall = false;
        await e.prompt();
        const { outcome } = await e.userChoice;
        if (outcome === 'accepted') pwa.installed = true;
        return;
    }
    if (pwa.isIos) pwa.iosSheet = true;
}

// the banner, once per device: a closed banner stays closed
const BANNER_KEY = 'bb.install.banner';
export function bannerDismissed(): boolean {
    try {
        return localStorage.getItem(BANNER_KEY) === '1';
    } catch {
        return false;
    }
}
export function dismissBanner() {
    try {
        localStorage.setItem(BANNER_KEY, '1');
    } catch {
        /* private mode: it shows again next time */
    }
}
