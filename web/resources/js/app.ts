import '../css/app.css';
import '../css/bookball.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { i18nVue } from 'laravel-vue-i18n';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { router } from '@inertiajs/vue3';
import { startEcho } from './lib/echo';
import TgAppGate from './components/TgAppGate.vue';
import { installSheetSwipe } from './lib/sheetSwipe';
import { unlockSound } from './lib/sound';

// live updates (Reverb): connect only while someone is logged in; follow logins / logouts done without a full page load
type AuthProps = { auth?: { user?: { id: number } | null } };
router.on('navigate', (e) => startEcho((e.detail.page.props as AuthProps).auth?.user?.id));

installSheetSwipe();   // bottom sheets close with a downward swipe on phones
unlockSound();   // phones allow the notification sound only after the first tap

// Fresh pages: something changed (booked, withdrew, accepted, joined…) → forget the preloaded pages, so the next page
// shows the change; and "back" asks the server again instead of showing the old page as it was.
router.on('finish', (e) => e.detail.visit.method !== 'get' && router.flushAll());
window.addEventListener('popstate', () => setTimeout(() => router.reload(), 0));
// back to Bookball after a while in another app or tab (phones, the Telegram Mini App): refresh the page's data
let hiddenAt = 0;
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') hiddenAt = Date.now();
    else if (hiddenAt && Date.now() - hiddenAt > 30_000 && !document.querySelector('.scrim')) router.reload();
});

createInertiaApp({
    title: (title) => (title ? `${title} · Bookball` : 'Bookball'),
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const locale = (props.initialPage.props.locale as string) || 'hy';
        document.documentElement.lang = locale;
        startEcho((props.initialPage.props as AuthProps).auth?.user?.id);
        // TgAppGate: inside Telegram (Mini App) logs in with Telegram and adapts the site; nothing on the web
        createApp({ render: () => [h(App, props), h(TgAppGate)] })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18nVue, {
                lang: locale,
                fallbackLang: 'en',
                resolve: async (lang: string) => {
                    const langs = import.meta.glob('../../lang/*.json');
                    return await langs[`../../lang/${lang}.json`]();
                },
            })
            .mount(el);
    },
    progress: false,   // no loading line at the top (product decision): pages are preloaded instead
    // pages preloaded on hover / press are kept only a few seconds (they are for the click that follows)
    defaults: { prefetch: { cacheFor: 5000, hoverDelay: 75 } },
});
