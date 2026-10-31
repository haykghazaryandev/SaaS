import { router } from '@inertiajs/vue3';

/**
 * The installed app's icon number (like a messenger): unread notices + chats (+ requests waiting, for a place's
 * people), the same number push sets while the app is closed (shared prop appBadge). Phones / browsers without
 * the Badging API: nothing.
 */
export function installAppBadge() {
    const nav = navigator as Navigator & { setAppBadge?: (n?: number) => Promise<void>; clearAppBadge?: () => Promise<void> };
    if (!nav.setAppBadge) return;
    const set = (n: unknown) => {
        if (typeof n !== 'number') return;
        (n > 0 ? nav.setAppBadge!(n) : nav.clearAppBadge!()).catch(() => {});
    };
    router.on('navigate', (e) => set((e.detail.page.props as { appBadge?: number | null }).appBadge));
}

/**
 * The on-screen keyboard (phones, the installed app above all): while it is open, html gets .kb-open and --vvh (the
 * height still visible). The bottom safe area (home indicator) is under the keyboard then, so no gap is kept for it,
 * the tab bar hides, and the chat fits the visible height (no big empty space above the keyboard on iPhone).
 */
export function watchKeyboard() {
    const vv = window.visualViewport;
    if (!vv) return;
    const root = document.documentElement;
    const update = () => {
        const open = window.innerHeight - vv.height > 140 && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName ?? '');
        root.classList.toggle('kb-open', open);
        root.style.setProperty('--vvh', `${Math.round(vv.height)}px`);
        // iPhone scrolls the whole page up to show the field; a full-height screen (the chat) already fits the
        // visible part, so keep its top (the header) in place
        if (open && document.querySelector('.chat')) window.scrollTo(0, 0);
    };
    vv.addEventListener('resize', update);
    document.addEventListener('focusin', () => setTimeout(update, 120));
    document.addEventListener('focusout', () => setTimeout(update, 120));
    update();
}
