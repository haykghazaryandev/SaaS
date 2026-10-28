<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import '../../css/owner.css';

import Avatar from '@/components/Avatar.vue';
import Flash from '@/components/Flash.vue';
import Icon from '@/components/Icon.vue';
import LangSwitch from '@/components/LangSwitch.vue';
import Logo from '@/components/Logo.vue';
import OwnerSheets from '@/components/owner/OwnerSheets.vue';
import { decisions, openForm, sheets, useOwner, type FormOpts } from '@/composables/useOwner';
import { useShared } from '@/composables/useShared';
import { useLive } from '@/lib/echo';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import QrScanner from '@/components/owner/QrScanner.vue';
import { computed, onBeforeUnmount, ref } from 'vue';

// Owner web: sidebar on desktop; top bar + bottom tabs on phones. Hosts the booking sheets for every owner page.
// newBooking: the page offers "+ New booking" (its header button on desktop, this floating button on phones).
// The language switch lives in Settings (G6: no language switch in the header for logged-in users).
const props = defineProps<{ title?: string; newBooking?: FormOpts }>();
const owner = useOwner();
const page = usePage();
const { user } = useShared();
const has = (f: string) => (owner.value.features ?? []).includes(f);
// the club pages show only where the admin switched them on (admin → place → features)
const club = computed(() =>
    [
        { name: 'owner.now', label: 'owner.nav.now', icon: 'clock', on: has('wallet') || has('shop') },
        { name: 'owner.players', label: 'owner.nav.players', icon: 'users', on: has('wallet') || has('rating') },
        { name: 'owner.cards', label: 'owner.nav.cards', icon: 'wallet', on: has('wallet') },
        { name: 'owner.shop', label: 'owner.nav.shop', icon: 'cube', on: has('shop') },
        { name: 'owner.tournaments', label: 'owner.nav.tournaments', icon: 'trophy', on: has('tournaments') },
        { name: 'owner.money', label: 'owner.nav.money', icon: 'chart', on: has('money') && owner.value.role === 'owner' },
    ]
        .filter((n) => n.on)
        .map((n) => ({ ...n, badge: 0 })),
);
const nav = computed(() => [
    // a club starts at the desk ("Now")
    ...club.value.filter((n) => n.name === 'owner.now'),
    { name: 'owner.today', label: 'common.today', icon: 'home', badge: Math.max(0, owner.value.pending - Object.keys(decisions.hidden).length) },
    { name: 'owner.schedule', label: 'owner.nav.schedule', icon: 'calendar', badge: 0 },
    ...club.value.filter((n) => n.name !== 'owner.now'),
    // the players' messages to the place (and the tournament chats): the chat list, unread on the badge
    {
        name: 'messages.index',
        label: 'owner.nav.messages',
        icon: 'chat',
        badge: Number((page.props.counts as { messages?: number } | null)?.messages ?? 0),
    },
    // every owner sees ZoneSpot's fee (Money: the whole report where the money feature is on)
    ...(owner.value.role === 'owner' && !has('money') ? [{ name: 'owner.money', label: 'owner.nav.money', icon: 'chart', badge: 0 }] : []),
    { name: 'owner.settings', label: 'owner.nav.settings', icon: 'settings', badge: 0 },
]);
// phones: a club: Now · Schedule · Scan · More (Today and the rest); others: the three tabs as before
const canScan = computed(() => has('wallet'));
const isClub = computed(() => club.value.some((n) => n.name === 'owner.now'));
const tabs = computed(() => (isClub.value ? nav.value.filter((n) => ['owner.now', 'owner.schedule'].includes(n.name)) : club.value.length ? nav.value.slice(0, 2) : nav.value));
const more = computed(() => (club.value.length ? nav.value.filter((n) => !tabs.value.includes(n)) : []));
const moreOpen = ref(false);
const scanOpen = ref(false);
const moreActive = computed(() => more.value.some((n) => current(n.name)));
const current = (name: string) => route().current(name);

function switchVenue(e: Event) {
    router.post(route('owner.venue'), { venue_id: Number((e.target as HTMLSelectElement).value) });
}
// sheets belong to one page visit
const off = router.on('navigate', () => {
    sheets.detail = null;
    sheets.form = null;
    sheets.reason = null;
});
onBeforeUnmount(off);

// live: a booking or closure of this place changed (a player sent a request, staff on another phone answered…):
// reload the page's data at once and let an open booking form check its time again
let reloadT: number | undefined;
useLive(
    () => (owner.value?.venue ? `venue.${owner.value.venue.id}` : null),
    'schedule.changed',
    (d) => {
        clearTimeout(reloadT);
        reloadT = window.setTimeout(() => router.reload(), 300);
        window.dispatchEvent(new CustomEvent('bb:schedule', { detail: d }));
    },
    true,
);
// the club: an item on a check, a card top-up, a payment, a tournament registration (another phone of the staff, a player)
useLive(
    () => (owner.value?.venue ? `venue.${owner.value.venue.id}` : null),
    'club.changed',
    () => {
        clearTimeout(reloadT);
        reloadT = window.setTimeout(() => router.reload(), 300);
    },
    true,
);
onBeforeUnmount(() => clearTimeout(reloadT));
</script>

<template>
    <Head :title="title || $t('owner.brand')" />
    <div class="oapp">
        <aside class="oside">
            <Link :href="route('owner.today')"><Logo :sub="$t('owner.brand_short')" /></Link>
            <div class="field vbox">
                <label for="vs1">{{ $t('owner.place') }}</label>
                <select v-if="owner.venues.length > 1" id="vs1" class="input vsel" :value="owner.venue.id" @change="switchVenue">
                    <option v-for="v in owner.venues" :key="v.id" :value="v.id">{{ v.name }}</option>
                </select>
                <div v-else id="vs1" class="vname">{{ owner.venue.name }}</div>
                <span v-if="owner.role === 'staff'" class="faint" style="font-size: 11.5px">{{ $t('owner.role_staff') }}</span>
            </div>
            <nav class="onav">
                <Link v-for="n in nav" :key="n.name" :href="route(n.name)" :aria-current="current(n.name) ? 'page' : undefined">
                    <Icon :name="n.icon" /><span>{{ $t(n.label) }}</span
                    ><span v-if="n.badge" class="nbadge">{{ n.badge }}</span>
                </Link>
            </nav>
            <div class="oside-foot">
                <LangSwitch />
                <a class="olink" :href="route('home', { as: 'player' })"><Icon name="share" size="sm" />{{ $t('owner.player_site') }}</a>
                <a v-if="user?.isAdmin" class="olink" href="/admin"><Icon name="settings" size="sm" />{{ $t('admin.nav.admin_panel') }}</a>
                <div class="ome">
                    <Avatar :id="user?.id" :name="user?.name" :src="user?.avatar" size="sm" />
                    <span
                        ><b>{{ user?.name }}</b
                        ><small>{{ user?.phone }}</small></span
                    >
                    <Link :href="route('logout')" method="post" as="button" class="x" :title="$t('owner.sign_out')" :aria-label="$t('owner.sign_out')"
                        ><Icon name="logout" size="sm"
                    /></Link>
                </div>
            </div>
        </aside>
        <header class="otop">
            <Link class="logo-mark" :href="route('owner.today')" :aria-label="$t('owner.brand')">
                <i class="logo-glyph" aria-hidden="true" />
            </Link>
            <select v-if="owner.venues.length > 1" class="input vsel" :value="owner.venue.id" :aria-label="$t('owner.place')" @change="switchVenue">
                <option v-for="v in owner.venues" :key="v.id" :value="v.id">{{ v.name }}</option>
            </select>
            <div v-else class="vname">{{ owner.venue.name }}</div>
            <LangSwitch />
            <a class="x" :href="route('home', { as: 'player' })" :title="$t('owner.player_site')" :aria-label="$t('owner.player_site')"
                ><Icon name="share" size="sm"
            /></a>
            <a v-if="user?.isAdmin" class="x" href="/admin" :title="$t('admin.nav.admin_panel')" :aria-label="$t('admin.nav.admin_panel')"><Icon name="settings" size="sm" /></a>
        </header>
        <main class="omain"><slot /></main>
        <button v-if="props.newBooking" type="button" class="ofab" data-test="fab" :aria-label="$t('common.new_booking')" :title="$t('common.new_booking')" @click="openForm(props.newBooking)">
            <Icon name="plus" /><span>{{ $t('common.new_booking') }}</span>
        </button>
        <nav class="tabbar">
            <Link v-for="n in tabs" :key="n.name" :href="route(n.name)" :aria-current="current(n.name) ? 'page' : undefined">
                <span class="tb-ico"
                    ><Icon :name="n.icon" size="lg" /><span v-if="n.badge" class="nbadge">{{ n.badge }}</span></span
                >{{ $t(n.label) }}
            </Link>
            <button v-if="canScan" type="button" class="tb-scan" data-test="scan" @click="scanOpen = true">
                <span class="tb-ico"><Icon name="qr" size="lg" /></span>{{ $t('owner.club.scan') }}
            </button>
            <button v-if="more.length" type="button" :aria-current="moreActive ? 'page' : undefined" @click="moreOpen = true">
                <span class="tb-ico"><Icon name="more" size="lg" /></span>{{ $t('owner.nav.more') }}
            </button>
        </nav>
        <!-- phones: the other pages -->
        <div v-if="moreOpen" class="scrim" @click.self="moreOpen = false">
            <div class="sheet" role="dialog" aria-modal="true">
                <nav class="moremenu">
                    <Link v-for="n in more" :key="n.name" :href="route(n.name)" :aria-current="current(n.name) ? 'page' : undefined" @click="moreOpen = false">
                        <Icon :name="n.icon" /><span>{{ $t(n.label) }}</span>
                    </Link>
                    <!-- leave the dashboard: the player app; the admin panel for admins (not part of this app: a full page load) -->
                    <a :href="route('home', { as: 'player' })"><Icon name="share" /><span>{{ $t('owner.player_site') }}</span></a>
                    <a v-if="user?.isAdmin" href="/admin"><Icon name="settings" /><span>{{ $t('admin.nav.admin_panel') }}</span></a>
                </nav>
            </div>
        </div>
        <QrScanner v-if="scanOpen" @close="scanOpen = false" />
        <OwnerSheets />
        <Flash />
        <ConfirmDialog />
    </div>
</template>
