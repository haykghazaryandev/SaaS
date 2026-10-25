// Installable web app ("Add to Home Screen"): app icon + full screen, the cheap step before the mobile app.
// The manifest and the icons are linked in app.blade.php; Android (Chrome) also wants a service worker before it
// offers the install, so public/sw.js is registered here. It caches nothing (see the file). iOS needs no worker.
import { inTelegram } from './telegram';

export function installPwa() {
    if (!('serviceWorker' in navigator) || inTelegram()) return; // inside Telegram we are already an app
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            /* http://, private mode…: the site simply stays a site */
        });
    });
}

/** Opened from the home screen icon (full screen), not in a browser tab. */
export const standalone = () =>
    matchMedia('(display-mode: standalone)').matches || (navigator as unknown as { standalone?: boolean }).standalone === true;
