<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import Logo from '@/components/Logo.vue';
import { xsrf } from '@/lib/echo';
import { tgApp, type TgWebApp } from '@/lib/telegram';
import { router, usePage } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

// Inside Telegram (Mini App) only: log the person in with Telegram's signed data (no login screen), and make the
// site feel at home there: Telegram's back button, full height, our dark colors, links opened by Telegram.
// A Telegram account we don't know yet shares its phone once (the bot's usual contact step), like "Log in with Telegram".
type State = 'off' | 'checking' | 'contact' | 'waiting' | 'failed';
const state = ref<State>('off');
const error = ref('');
const page = usePage();
let tg: TgWebApp | null = null;
let token = '';

async function call(method: 'GET' | 'POST', path: string, body?: object) {
    const r = await fetch(path, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.error || data.message || 'error');
    return data;
}

/** Go where the server says; the admin panel is not an Inertia page. */
function go(to: string | null | undefined) {
    if (!to) return router.reload();
    const path = new URL(to, location.origin).pathname;
    if (path.startsWith('/admin')) location.href = to;
    else router.visit(to, { replace: true });
}

async function login() {
    if (!tg) return;
    state.value = 'checking';
    try {
        const d = await call('POST', route('login.telegram.app'), { init_data: tg.initData, path: location.pathname + location.search });
        if (d.status === 'need_contact') {
            token = d.token;
            state.value = 'contact';
            return;
        }
        state.value = 'off';
        const userId = (page.props.auth as { user?: { id: number } | null })?.user?.id;
        // already logged in as this person on this page: nothing to do; otherwise show the page with the account
        const samePage = new URL(d.redirect, location.origin).pathname === location.pathname;
        if (userId !== d.user_id) samePage ? router.reload() : go(d.redirect);
        else if (!samePage) go(d.redirect);
    } catch (e) {
        error.value = (e as Error).message;
        state.value = 'failed';
    }
}

/** "Share my phone": Telegram sends the contact to our bot, which confirms the login; then we finish it here. */
function sharePhone() {
    if (!tg) return;
    tg.requestContact((shared) => {
        if (!shared) return;
        state.value = 'waiting';
        poll(0);
    });
}
function poll(n: number) {
    setTimeout(async () => {
        try {
            const d = await call('GET', route('login.telegram.status', token));
            if (d.status === 'approved') {
                const done = await call('POST', route('login.telegram.complete', token), { context: 'login' });
                state.value = 'off';
                return go(done.redirect);
            }
        } catch {
            /* keep trying */
        }
        if (n < 40) poll(n + 1);
        else state.value = 'contact';
    }, 1500);
}

function setUp(w: TgWebApp) {
    document.documentElement.classList.add('in-tg');
    // iPhone: keep bottom bars above the home indicator even if Telegram reports no safe area
    if (w.platform === 'ios') document.documentElement.classList.add('tg-ios');
    w.ready();
    w.expand();
    try {
        w.setHeaderColor('#0a0d0b');
        w.setBackgroundColor('#0a0d0b');
        w.disableVerticalSwipes?.();   // scrolling a long page must not close the app
    } catch {
        /* older Telegram apps */
    }
    // back from another app (or Telegram shrank us to half height): full height again, so sheets fit
    const reexpand = () => !w.isExpanded && w.expand();
    w.onEvent?.('activated', reexpand);
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && reexpand());
        // Telegram's own back button instead of the browser's
    const syncBack = () => (location.pathname === '/' ? w.BackButton.hide() : w.BackButton.show());
    w.BackButton.onClick(() => (history.length > 1 ? history.back() : router.visit('/')));
    router.on('navigate', syncBack);
    syncBack();
    // links leaving Bookball (maps, other sites, t.me) open through Telegram, not inside this small window
    document.addEventListener(
        'click',
        (e) => {
            const a = (e.target as HTMLElement).closest('a[href]') as HTMLAnchorElement | null;
            if (!a || !/^https?:/.test(a.href) || new URL(a.href).origin === location.origin) return;
            e.preventDefault();
            if (/^https:\/\/t\.me\//.test(a.href)) w.openTelegramLink(a.href);
            else w.openLink(a.href);
        },
        true,
    );
}

onMounted(() => {
    tg = tgApp();
    if (!tg) return;
    setUp(tg);
    login();
});
</script>

<template>
    <div v-if="state !== 'off'" class="tg-gate" role="dialog" aria-modal="true" aria-live="polite">
        <div class="box">
            <Logo />
            <template v-if="state === 'checking' || state === 'waiting'">
                <span class="spin" aria-hidden="true" />
                <p class="muted">{{ $t(state === 'waiting' ? 'tgapp.waiting' : 'tgapp.checking') }}</p>
            </template>
            <template v-else-if="state === 'contact'">
                <h2>{{ $t('tgapp.welcome') }}</h2>
                <p class="muted">{{ $t('tgapp.contact') }}</p>
                <button type="button" class="btn block lg" @click="sharePhone"><Icon name="phone" size="sm" />{{ $t('tgapp.share') }}</button>
                <button type="button" class="btn link" @click="state = 'off'">{{ $t('tgapp.later') }}</button>
            </template>
            <template v-else>
                <p class="err">{{ error || $t('tgapp.failed') }}</p>
                <button type="button" class="btn block" @click="login">{{ $t('tgapp.retry') }}</button>
                <button type="button" class="btn link" @click="state = 'off'">{{ $t('tgapp.later') }}</button>
            </template>
        </div>
    </div>
</template>

<style scoped>
.tg-gate { position: fixed; inset: 0; z-index: 200; background: var(--bg); display: grid; place-items: center; padding: 24px 16px; }
.box { width: 100%; max-width: 360px; display: flex; flex-direction: column; align-items: center; gap: 14px; text-align: center; }
.box h2 { font-size: 22px; margin: 6px 0 0; }
.box p { margin: 0; }
.err { color: var(--hot); }
.spin { width: 28px; height: 28px; border-radius: 50%; border: 3px solid var(--line-2); border-top-color: var(--accent); animation: tgspin 0.8s linear infinite; }
@keyframes tgspin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spin { animation-duration: 3s; } }
</style>
