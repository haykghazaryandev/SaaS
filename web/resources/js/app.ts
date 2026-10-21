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

// live updates (Reverb): connect only while someone is logged in; follow logins / logouts done without a full page load
type AuthProps = { auth?: { user?: { id: number } | null } };
router.on('navigate', (e) => startEcho((e.detail.page.props as AuthProps).auth?.user?.id));

createInertiaApp({
    title: (title) => (title ? `${title} · Bookball` : 'Bookball'),
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const locale = (props.initialPage.props.locale as string) || 'hy';
        document.documentElement.lang = locale;
        startEcho((props.initialPage.props as AuthProps).auth?.user?.id);
        createApp({ render: () => h(App, props) })
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
    progress: { color: '#c8ff3d' },
});
