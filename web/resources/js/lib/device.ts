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
 * The on-screen keyboard (phones, the installed app above all): while it is open, html gets .kb-open, --vvh (the
 * height still visible) and --vvt (where the visible part starts: iPhone slides the page up under the keyboard).
 * The bottom safe area (home indicator) is under the keyboard then, so no gap is kept for it, the tab bar hides, and
 * the chat is pinned to the visible part: its header on screen, its bottom on the keyboard.
 * "Open" compares with the height seen without the keyboard: in the installed app on iOS 26 window.innerHeight
 * shrinks with the keyboard too, so it can't be the reference.
 * ?kbdebug=1 (once; ?kbdebug=0 turns it off) shows the numbers on screen, to check a phone we can't plug in.
 */
export function watchKeyboard() {
    const vv = window.visualViewport;
    if (!vv) return;
    const root = document.documentElement;
    const typing = () => {
        const el = document.activeElement as HTMLElement | null;
        return !!el && (/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || el.isContentEditable);
    };
    let full = vv.height;
    let wasOpen = false;
    let last = '';
    let frame = 0;
    const apply = () => {
        frame = 0;
        const t = typing();
        // no field focused: the keyboard is closed, this is the whole height (it also follows a turn of the phone)
        if (!t) full = vv.height;
        const open = t && full - vv.height > 120;
        const h = Math.round(vv.height);
        const top = open ? Math.max(0, Math.round(vv.offsetTop)) : 0;
        const key = `${open}|${h}|${top}`;
        if (key !== last) {
            // only real changes touch the page: no back-and-forth with iPhone's own scrolling
            last = key;
            root.classList.toggle('kb-open', open);
            root.style.setProperty('--vvh', `${h}px`);
            root.style.setProperty('--vvt', `${top}px`);
        }
        // keyboard closed: put back the page iPhone slid up
        if (wasOpen && !open && document.querySelector('.papp.fs')) window.scrollTo(0, 0);
        wasOpen = open;
        if (debug)
            debug.textContent = `kb ${open ? 'OPEN' : 'closed'} · full ${Math.round(full)} · vv ${h} @${Math.round(vv.offsetTop)} · inner ${window.innerHeight} · scrollY ${Math.round(window.scrollY)} · screen ${screen.height} · sat ${sat} · ${navigator.userAgent.match(/OS [\d_]+/)?.[0] ?? ''}`;
    };
    const update = () => {
        if (!frame) frame = requestAnimationFrame(apply);
    };

    let debug: HTMLElement | null = null;
    let sat = 0;
    try {
        const q = new URLSearchParams(location.search).get('kbdebug');
        if (q !== null) localStorage.setItem('zs.kbdebug', q);
        if (localStorage.getItem('zs.kbdebug') === '1') {
            debug = document.createElement('div');
            debug.style.cssText =
                'position:fixed;left:4px;right:4px;top:calc(env(safe-area-inset-top) + 2px);z-index:9999;font:11px/1.3 monospace;color:#fff;background:rgba(200,0,0,.85);padding:4px 6px;border-radius:6px;pointer-events:none';
            document.body.appendChild(debug);
            const probe = document.createElement('div');
            probe.style.cssText = 'position:fixed;top:0;height:env(safe-area-inset-top);width:0;visibility:hidden';
            document.body.appendChild(probe);
            sat = probe.offsetHeight;
        }
    } catch {
        // no storage: no debug
    }

    vv.addEventListener('resize', update);
    vv.addEventListener('scroll', update);
    document.addEventListener('focusin', () => setTimeout(update, 60));
    document.addEventListener('focusout', () => setTimeout(update, 60));
    update();
}
