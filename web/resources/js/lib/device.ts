import { social } from '@/composables/useSocial';
import { router } from '@inertiajs/vue3';
import { watch } from 'vue';

/**
 * The installed app's icon number: one per unread notice, one per chat with unread messages (+ requests waiting, for a place's
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
    // the page's number, and the unread notices + chats it was made with
    let base: number | null = null;
    let baseLive = 0;
    router.on('navigate', (e) => {
        const p = e.detail.page.props as { appBadge?: number | null; counts?: { notifications?: number; chats?: number } | null };
        base = typeof p.appBadge === 'number' ? p.appBadge : null;
        baseLive = (p.counts?.notifications ?? 0) + (p.counts?.chats ?? 0);
        set(base);
    });
    // a chat message or a notice arriving live (or read) while the app is open: the icon follows at once
    watch(
        () => social.counts.notifications + (social.counts.chats ?? 0),
        (live) => base !== null && set(Math.max(0, base - baseLive + live)),
    );
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
    let wasOpen = false;
    const update = () => {
        const open = window.innerHeight - vv.height > 140 && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName ?? '');
        root.classList.toggle('kb-open', open);
        root.style.setProperty('--vvh', `${Math.round(vv.height)}px`);
        // iPhone slides the page up to show the field: --vvt is where the visible part starts, the chat (fixed while
        // the keyboard is open) sits there, so its header stays on screen and its bottom touches the keyboard
        root.style.setProperty('--vvt', `${Math.round(vv.offsetTop)}px`);
        // keyboard closed: put back the page iPhone slid up
        if (wasOpen && !open && document.querySelector('.chat')) window.scrollTo(0, 0);
        wasOpen = open;
    };
    vv.addEventListener('resize', update);
    vv.addEventListener('scroll', update);
    document.addEventListener('focusin', () => setTimeout(update, 120));
    document.addEventListener('focusout', () => setTimeout(update, 120));
    update();
}
