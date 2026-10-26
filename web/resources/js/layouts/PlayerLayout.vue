<script setup lang="ts">
/**
 * Player web layout: sticky header (logo, nav, bell, account menu), the page, and the bottom tab bar on phones.
 *
 * Props (all optional):
 *   hideTabbar   no bottom tab bar on phones (e.g. the place page, which has its own sticky booking bar)
 *   fullscreen   phones / tablets (≤ 900px): no header, no tab bar, no footer; <main> is a flex column that fills
 *                100dvh, so the page can use `flex: 1` (the chat page). Desktop keeps the normal header.
 *
 * Rules: the language switch is always in the header (product owner's choice; also in Profile);
 * the footer is shown to guests only (G7).
 *
 * CSS variables set on .papp for pages that size themselves to the screen:
 *   --app-top     height taken by the header (64px, or 0px in fullscreen on phones)
 *   --app-bottom  height taken by the tab bar (76px + safe area on phones when it is shown, else 0px)
 *   e.g. `height: calc(100dvh - var(--app-top) - var(--app-bottom))`
 */
import Avatar from '@/components/Avatar.vue';
import Flash from '@/components/Flash.vue';
import Icon from '@/components/Icon.vue';
import LangSwitch from '@/components/LangSwitch.vue';
import Logo from '@/components/Logo.vue';
import NotifyBell from '@/components/social/NotifyBell.vue';
import { useShared } from '@/composables/useShared';
import { setCounts, social, startLive } from '@/composables/useSocial';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// load a page when the pointer is over a link or it is pressed, so it opens without waiting
const PREFETCH: ('hover' | 'click')[] = ['hover', 'click'];

const props = withDefaults(defineProps<{ hideTabbar?: boolean; fullscreen?: boolean }>(), { hideTabbar: false, fullscreen: false });

const { user } = useShared();
const page = usePage<SharedData>();
const has = (name: string) => {
    try {
        return route().has(name);
    } catch {
        return false;
    }
};

// label: desktop nav (wide screens); short: narrow desktop nav; tab (or short): the phone tab bar (5 must fit in 390px)
type NavItem = { key: string; href: string; label: string; short: string; tab?: string; icon: string; match: string[]; badge?: number; warn?: boolean };
const nav = computed<NavItem[]>(() => {
    const c = social.counts;
    const items: NavItem[] = [{ key: 'fields', href: route('home'), label: 'nav.home', short: 'nav.home', icon: 'home', match: ['/', '/places', '/t/'] }];
    // games of the chosen sport only: none on "What do you play?"
    if (has('games.index') && page.props.sport) items.push({ key: 'games', href: route('games.index', { sport: page.props.sport }), label: 'common.games', short: 'common.games', icon: 'ball', match: ['/games'], badge: user.value ? c.invites : 0 });
    if (user.value) {
        if (has('messages.index')) items.push({ key: 'chats', href: route('messages.index'), label: 'nav.chats', short: 'nav.chats', icon: 'chat', match: ['/messages'], badge: c.messages });
        items.push({ key: 'friends', href: route('friends.index'), label: 'common.friends', short: 'common.friends', icon: 'users', match: ['/friends', '/u/'], badge: c.friendRequests });
        items.push({ key: 'bookings', href: route('me.bookings'), label: 'common.my_bookings', short: 'common.bookings', tab: 'nav.tab_bookings', icon: 'ticket', match: ['/me/bookings'], badge: c.pendingBookings, warn: true });
    }
    return items;
});
// phones: the same items (5 at most), plus "Log in" for guests
const tabs = computed(() => [...nav.value, ...(user.value ? [] : [{ key: 'login', href: route('login'), label: 'nav.login', short: 'nav.login', icon: 'user', match: ['/login'] } as NavItem])].slice(0, 5));
const path = computed(() => page.url.split('?')[0]);
const active = (m: string[]) => m.some((p) => (p === '/' ? path.value === '/' : path.value === p || path.value.startsWith(p.endsWith('/') ? p : p + '/')));
const badge = (n?: number) => (n ? (n > 99 ? '99+' : String(n)) : '');
const showTabbar = computed(() => !props.hideTabbar && !props.fullscreen);

// Instagram-like: the bottom menu slides away while scrolling down and comes back when scrolling up (or near the top)
const tabHidden = ref(false);
let lastY = 0;
let ticking = false;
function onScroll() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(() => {
        const y = window.scrollY;
        const dy = y - lastY;
        if (y < 60) tabHidden.value = false;
        else if (dy > 8) tabHidden.value = true;
        else if (dy < -8) tabHidden.value = false;
        if (Math.abs(dy) > 8 || y < 60) lastY = y;
        ticking = false;
    });
}
onMounted(() => {
    lastY = window.scrollY;
    window.addEventListener('scroll', onScroll, { passive: true });
});
onBeforeUnmount(() => window.removeEventListener('scroll', onScroll));

// badges and live notices
watch(() => page.props.counts, (c) => setCounts(c), { immediate: true });
watch(() => user.value?.id, (id) => startLive(id), { immediate: true });

// account menu
const menu = ref(false);
const menuEl = ref<HTMLElement>();
const who = computed(() => user.value?.name || user.value?.phone || '');
function onDoc(e: MouseEvent) {
    if (menu.value && menuEl.value && !menuEl.value.contains(e.target as Node)) menu.value = false;
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') menu.value = false;
}
onMounted(() => {
    document.addEventListener('click', onDoc);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDoc);
    document.removeEventListener('keydown', onKey);
});
watch(() => page.url, () => (menu.value = false));
</script>

<template>
    <div :class="['papp', { 'with-tabbar': showTabbar, fs: fullscreen, guest: !user }]">
        <header class="top">
            <div class="wrap">
                <Link :href="route('home')" class="brand" :prefetch="PREFETCH" :aria-label="'Bookball'"><Logo /></Link>
                <nav class="nav" :aria-label="$t('nav.main')">
                    <Link v-for="n in nav" :key="n.key" :href="n.href" :prefetch="PREFETCH" :aria-current="active(n.match) ? 'page' : undefined">
                        <Icon :name="n.icon" /><span class="nl">{{ $t(n.label) }}</span><span class="ns">{{ $t(n.short) }}</span
                        ><span v-if="badge(n.badge)" :class="['badge-dot', { warn: n.warn }]">{{ badge(n.badge) }}</span>
                    </Link>
                </nav>
                <div class="top-right">
                    <LangSwitch />
                    <template v-if="!user">
                        <Link class="btn sm" :href="route('login')">{{ $t('nav.login') }}</Link>
                    </template>
                    <template v-else>
                        <Link v-if="user.isOwner" class="btn outline sm owner-btn" :href="route('owner.today')"><Icon name="chart" size="sm" /><span>{{ $t('nav.owner') }}</span></Link>
                        <NotifyBell />
                        <div ref="menuEl" class="umenu">
                            <button type="button" class="ubtn" :aria-expanded="menu" aria-haspopup="menu" :aria-label="$t('common.account')" :title="who" @click="menu = !menu">
                                <Avatar :id="user.id" :name="user.name || user.phone" :src="user.avatar" size="sm" />
                            </button>
                            <div v-if="menu" class="menu udrop" role="menu">
                                <div class="uhead">
                                    <Avatar :id="user.id" :name="user.name || user.phone" :src="user.avatar" size="md" />
                                    <span class="uwho">
                                        <b>{{ user.name || $t('common.account') }}</b>
                                        <span>{{ user.username ? '@' + user.username : user.phone }}</span>
                                    </span>
                                </div>
                                <Link role="menuitem" :href="route('me.profile')" :prefetch="PREFETCH"><Icon name="user" size="sm" />{{ $t('profile.title') }}</Link>
                                <Link role="menuitem" :href="route('me.bookings')" :prefetch="PREFETCH"><Icon name="ticket" size="sm" />{{ $t('common.my_bookings') }}</Link>
                                <Link v-if="!user.registered" role="menuitem" :href="route('welcome')"><Icon name="check" size="sm" />{{ $t('player.my_bookings.finish_profile') }}</Link>
                                <Link v-if="user.isOwner" role="menuitem" :href="route('owner.today')"><Icon name="chart" size="sm" />{{ $t('nav.owner') }}</Link>
                                <hr />
                                <Link role="menuitem" :href="route('logout')" method="post" as="button" class="out"><Icon name="logout" size="sm" />{{ $t('common.log_out') }}</Link>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </header>
        <main class="wrap app-main"><slot /></main>
        <footer v-if="!user" class="foot">
            <div class="wrap">
                <span>© 2026 Bookball · {{ $t('common.city') }}</span>
            </div>
        </footer>
        <nav v-if="showTabbar" :class="['tabbar', { 'tab-hide': tabHidden }]" :aria-label="$t('nav.main')">
            <Link v-for="n in tabs" :key="n.key" :href="n.href" :title="$t(n.tab ?? n.short)" :prefetch="PREFETCH" :aria-current="active(n.match) ? 'page' : undefined">
                <span class="ti"><Icon :name="n.icon" size="lg" /><span v-if="badge(n.badge)" :class="['badge-dot', 'tb', { warn: n.warn }]">{{ badge(n.badge) }}</span></span>
                <span class="tl">{{ $t(n.tab ?? n.short) }}</span>
            </Link>
        </nav>
    </div>
    <Flash />
</template>

<style scoped>
.papp { --app-top: 64px; --app-bottom: 0px; min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column; }
.app-main { flex: 1 0 auto; width: 100%; padding-bottom: 40px; }
.papp.guest .app-main { padding-bottom: 0; }
@media (max-width: 900px) {
    .papp.with-tabbar { --app-bottom: calc(84px + var(--tab-gap)); }
    .app-main { padding-bottom: 24px; }
    /* fullscreen (chat on phones): the page fills the screen */
    .papp.fs { --app-top: 0px; height: 100dvh; min-height: 0; overflow: hidden; }
    .papp.fs .top { display: none; }
    .papp.fs .app-main { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; padding-bottom: 0; }
}

.brand { flex: none; }
.nav a { position: relative; white-space: nowrap; }
.nav .ns { display: none; }
.nav .badge-dot { margin-left: 2px; }
@media (max-width: 1120px) { .nav .nl { display: none; } .nav .ns { display: inline; } }
@media (max-width: 1240px) { .nav .ico { display: none; } .nav a { padding: 8px 10px; } }
@media (max-width: 600px) { .owner-btn { display: none; } }

.tabbar .ti { position: relative; display: grid; place-items: center; }
.tabbar .tb { position: absolute; top: -6px; left: calc(100% - 8px); box-shadow: 0 0 0 2px rgb(12, 16, 13); }

.umenu { position: relative; }
.ubtn { display: grid; place-items: center; width: 38px; height: 38px; padding: 0; border-radius: 50%; border: 2px solid transparent; background: none; transition: border-color .15s; }
.ubtn:hover { border-color: var(--line-2); }
.ubtn[aria-expanded="true"] { border-color: var(--accent); }
.udrop { min-width: 240px; }
.uhead { padding: 8px 10px 10px; display: flex; align-items: center; gap: 10px; }
.uwho { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.uwho b { font-size: 14.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.uwho span { font-size: 12.5px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.udrop .out { color: var(--muted); }
.udrop .out:hover { color: var(--hot); background: var(--hot-soft); }
</style>
