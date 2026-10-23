<script setup lang="ts">
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

// Player web: header with navigation, bell, language and account menu; bottom tab bar on phones (max 5 items).
const { user } = useShared();
const page = usePage<SharedData>();
const has = (name: string) => {
    try {
        return route().has(name);
    } catch {
        return false;
    }
};

type NavItem = { key: string; href: string; label: string; short: string; icon: string; match: string[]; badge?: number };
const nav = computed<NavItem[]>(() => {
    const items: NavItem[] = [{ key: 'fields', href: route('home'), label: 'nav.fields', short: 'nav.fields', icon: 'grid', match: ['/', '/places'] }];
    if (has('games.index')) items.push({ key: 'games', href: route('games.index'), label: 'nav.games', short: 'nav.games', icon: 'ball', match: ['/games'] });
    if (user.value) {
        if (has('messages.index'))
            items.push({ key: 'messages', href: route('messages.index'), label: 'nav.messages', short: 'friends.nav.messages', icon: 'chat', match: ['/messages'], badge: social.counts.messages });
        items.push({ key: 'friends', href: route('friends.index'), label: 'nav.friends', short: 'nav.friends', icon: 'users', match: ['/friends', '/u/'], badge: social.counts.friendRequests });
        items.push({ key: 'bookings', href: route('me.bookings'), label: 'nav.bookingsShort', short: 'nav.bookingsShort', icon: 'ticket', match: ['/me/bookings'] });
    }
    return items;
});
// phones: the same items (5 at most), plus "Log in" for guests
const tabs = computed(() => [...nav.value, ...(user.value ? [] : [{ key: 'login', href: route('login'), label: 'nav.login', short: 'nav.login', icon: 'user', match: ['/login'] } as NavItem])].slice(0, 5));
const path = computed(() => page.url.split('?')[0]);
const active = (m: string[]) => m.some((p) => (p === '/' ? path.value === '/' : path.value === p || path.value.startsWith(p.endsWith('/') ? p : p + '/')));
const badge = (n?: number) => (n ? (n > 99 ? '99+' : String(n)) : '');

// badges and live notices
watch(() => page.props.counts, (c) => setCounts(c), { immediate: true });
watch(() => user.value?.id, (id) => startLive(id), { immediate: true });

// account menu
const menu = ref(false);
const menuEl = ref<HTMLElement>();
const initial = computed(() => (user.value?.name || user.value?.phone || '?').replace('+374', '').trim().slice(0, 1).toUpperCase());
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
    <header class="top">
        <div class="wrap">
            <Link :href="route('home')" class="brand"><Logo /></Link>
            <nav class="nav">
                <Link v-for="n in nav" :key="n.key" :href="n.href" :aria-current="active(n.match) ? 'page' : undefined">
                    <Icon :name="n.icon" /><span class="nl">{{ $t(n.label) }}</span><span class="ns">{{ $t(n.short) }}</span><span v-if="badge(n.badge)" class="nb">{{ badge(n.badge) }}</span>
                </Link>
            </nav>
            <div class="top-right">
                <Link v-if="user?.isOwner" class="btn ghost sm owner-btn" :href="route('owner.today')"><Icon name="chart" size="sm" /><span>{{ $t('nav.owner') }}</span></Link>
                <LangSwitch />
                <Link v-if="!user" class="btn sm" :href="route('login')">{{ $t('nav.login') }}</Link>
                <template v-else>
                    <NotifyBell />
                    <div ref="menuEl" class="umenu">
                        <button type="button" class="avatar" :aria-expanded="menu" aria-haspopup="menu" :title="user.name || user.phone" @click="menu = !menu">{{ initial }}</button>
                        <div v-if="menu" class="udrop" role="menu">
                            <div class="uhead">
                                <b>{{ user.name || $t('player.menu.account') }}</b>
                                <span class="mono">{{ user.username ? '@' + user.username : user.phone }}</span>
                            </div>
                            <Link role="menuitem" :href="route('me.profile')"><Icon name="user" size="sm" />{{ $t('profile.title') }}</Link>
                            <Link role="menuitem" :href="route('me.bookings')"><Icon name="ticket" size="sm" />{{ $t('nav.bookings') }}</Link>
                            <Link v-if="!user.registered" role="menuitem" :href="route('welcome')"><Icon name="check" size="sm" />{{ $t('player.my.finish') }}</Link>
                            <Link v-if="user.isOwner" role="menuitem" :href="route('owner.today')"><Icon name="chart" size="sm" />{{ $t('nav.owner') }}</Link>
                            <Link role="menuitem" :href="route('logout')" method="post" as="button" class="out"><Icon name="logout" size="sm" />{{ $t('nav.logout') }}</Link>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </header>
    <main class="wrap"><slot /></main>
    <footer class="foot">
        <div class="wrap">
            <span>© 2026 Bookball · {{ $t('foot.city') }}</span>
        </div>
    </footer>
    <nav class="tabbar">
        <Link v-for="n in tabs" :key="n.key" :href="n.href" :aria-current="active(n.match) ? 'page' : undefined">
            <span class="ti"><Icon :name="n.icon" size="lg" /><span v-if="badge(n.badge)" class="tb">{{ badge(n.badge) }}</span></span>
            <span class="tl">{{ $t(n.short) }}</span>
        </Link>
    </nav>
    <Flash />
</template>

<style scoped>
.brand { flex: none; }
.nav a { position: relative; white-space: nowrap; }
.nav .ns { display: none; }
@media (max-width: 1120px) { .nav .nl { display: none; } .nav .ns { display: inline; } }
.nb, .tb { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 99px; background: var(--accent); color: var(--on-accent); font-size: 10.5px; font-weight: 800; display: inline-grid; place-items: center; line-height: 1; }
@media (max-width: 1240px) { .nav .ico { display: none; } .nav a { padding: 8px 10px; } }
@media (max-width: 600px) { .owner-btn { display: none; } }
.tabbar .ti { position: relative; display: grid; place-items: center; }
.tabbar .tb { position: absolute; top: -6px; left: calc(100% - 8px); border: 2px solid rgb(12, 16, 13); height: 18px; }
.tabbar .tl { max-width: 100%; padding: 0 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tabbar a { min-width: 0; }
.umenu { position: relative; }
.umenu .avatar { cursor: pointer; color: var(--ink); padding: 0; }
.umenu .avatar[aria-expanded="true"] { border-color: var(--accent); }
.udrop { position: absolute; right: 0; top: calc(100% + 8px); min-width: 230px; background: var(--surface); border: 1px solid var(--line-2); border-radius: 16px; box-shadow: var(--shadow); padding: 6px; z-index: 60; display: flex; flex-direction: column; }
.uhead { padding: 10px 12px 10px; border-bottom: 1px solid var(--line); margin-bottom: 4px; display: flex; flex-direction: column; gap: 2px; }
.uhead b { font-size: 14.5px; }
.uhead span { font-size: 12.5px; color: var(--muted); }
.udrop a, .udrop button { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 10px; font-weight: 700; font-size: 14px; color: var(--ink); background: none; border: 0; text-align: left; width: 100%; }
.udrop a:hover, .udrop button:hover { background: var(--surface-2); }
.udrop .out { color: var(--muted); }
.udrop .out:hover { color: var(--hot); }
</style>
