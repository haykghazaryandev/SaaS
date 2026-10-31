<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ChatDock from '@/components/owner/ChatDock.vue';
import RequestsBell from '@/components/owner/RequestsBell.vue';
import { ping } from '@/lib/sound';
import '../../css/owner.css';

import Avatar from '@/components/Avatar.vue';
import Flash from '@/components/Flash.vue';
import Icon from '@/components/Icon.vue';
import LangSwitch from '@/components/LangSwitch.vue';
import Logo from '@/components/Logo.vue';
import OwnerSheets from '@/components/owner/OwnerSheets.vue';
import InstallSheet from '@/components/pwa/InstallSheet.vue';
import { decisions, openForm, sheets, useMay, useOwner, type FormOpts, type MenuKey } from '@/composables/useOwner';
import { useShared } from '@/composables/useShared';
import { useLive } from '@/lib/echo';
import { installable, promptInstall } from '@/lib/pwa';
import { Head, Link, router } from '@inertiajs/vue3';
import QrScanner from '@/components/owner/QrScanner.vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

// Owner web: sidebar on desktop; top bar + bottom tabs on phones. Hosts the booking sheets for every owner page.
// newBooking: the page offers "+ New booking" (its header button on desktop, this floating button on phones).
// The language switch lives in Settings (G6: no language switch in the header for logged-in users).
const props = defineProps<{ title?: string; newBooking?: FormOpts }>();
const owner = useOwner();
const { user } = useShared();
// the menus of this panel: the ones the place has (admin → place → features), left on by the owner in Settings → Menus
// for their own panel or for staff; ZoneSpot's fee, the team and the settings are the owner's only
const isOwner = computed(() => owner.value.role === 'owner');
const may = useMay();
const shows = (k: MenuKey) => owner.value.menus.available.includes(k) && owner.value.menus[isOwner.value ? 'owner' : 'staff'][k];
const club = computed(() =>
    (
        [
        { name: 'owner.now', key: 'now', label: 'owner.nav.now', icon: 'clock' },
        { name: 'owner.players', key: 'players', label: 'owner.nav.our_players', icon: 'users' },
        { name: 'owner.cards', key: 'cards', label: 'owner.nav.cards', icon: 'wallet' },
        { name: 'owner.shop', key: 'shop', label: 'owner.nav.shop', icon: 'cube' },
        { name: 'owner.tournaments', key: 'tournaments', label: 'owner.nav.tournaments', icon: 'trophy' },
        { name: 'owner.money', key: 'money', label: 'owner.nav.money', icon: 'chart' },
        ] as const
    )
        .filter((n) => shows(n.key))
        .map((n) => ({ ...n, badge: 0 })),
);
const nav = computed(() => [
    // a club starts at the desk ("Now")
    ...club.value.filter((n) => n.name === 'owner.now'),
    // the schedule (the day's numbers on top)
    ...(shows('schedule') ? [{ name: 'owner.schedule', label: 'owner.nav.schedule', icon: 'calendar', badge: 0 }] : []),
    // the requests waiting for an answer (several for the same time: together, the best first)
    ...(shows('requests')
        ? [{ name: 'owner.requests', label: 'owner.nav.requests', icon: 'bell', badge: Math.max(0, owner.value.pending - Object.keys(decisions.hidden).length) }]
        : []),
    ...club.value.filter((n) => n.name !== 'owner.now'),
    // the players' messages to the place (and the tournament chats): the chat list, unread on the badge
    ...(shows('messages')
        ? [{ name: 'owner.messages', label: 'owner.nav.messages', icon: 'chat', badge: owner.value.unreadChats ?? 0 }]
        : []),
    // the owner: ZoneSpot's fee (just its %), who works here, the place's settings
    ...(isOwner.value
        ? [
              { name: 'owner.fee', label: 'owner.nav.fee', icon: 'percent', badge: 0 },
              { name: 'owner.team', label: 'owner.nav.team', icon: 'team', badge: 0 },
              { name: 'owner.settings', label: 'owner.nav.settings', icon: 'settings', badge: 0 },
          ]
        : []),
]);
// phones: at most 4 slots in the bottom bar. A club: Now · Schedule · Scan · More; others: the first pages, the rest under More
const TAB_SLOTS = 4;
// scanning is for places with cards (may() alone is always true for the owner)
const canScan = computed(() => owner.value.menus.available.includes('cards') && may('cards'));
const isClub = computed(() => club.value.some((n) => n.name === 'owner.now'));
const tabs = computed(() => {
    // a place with the desk: Now · Schedule, then Scan (cards) or the next page (Requests) in the free slot
    if (isClub.value) {
        const first = nav.value.filter((n) => ['owner.now', 'owner.schedule'].includes(n.name));
        const rest = nav.value.filter((n) => !first.includes(n));
        return canScan.value || rest.length <= 2 ? [...first, ...(canScan.value ? [] : rest)] : [...first, rest[0]];
    }
    const room = TAB_SLOTS - (canScan.value ? 1 : 0);
    return nav.value.length <= room ? nav.value : nav.value.slice(0, room - 1);
});
const more = computed(() => nav.value.filter((n) => !tabs.value.includes(n)));
const moreBadge = computed(() => more.value.reduce((sum, n) => sum + n.badge, 0));
const moreOpen = ref(false);
const scanOpen = ref(false);
const moreActive = computed(() => more.value.some((n) => current(n.name)));
const current = (name: string) => route().current(name) || route().current(name + '.*');

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
// a new request (live): a sound, unless switched off in Profile
watch(
    () => owner.value.pending,
    (n, old) => {
        if (old !== undefined && n > old && (user.value as { sound?: boolean } | null)?.sound !== false) ping();
    },
);
</script>

<template>
    <Head :title="title || $t('owner.brand')" />
    <div class="oapp">
        <aside class="oside">
            <Link :href="route('owner.start')"><Logo :sub="$t('owner.brand_short')" /></Link>
            <div class="field vbox">
                <label for="vs1">{{ $t('owner.place') }}</label>
                <select v-if="owner.venues.length > 1" id="vs1" class="input vsel" :value="owner.venue.id" @change="switchVenue">
                    <option v-for="v in owner.venues" :key="v.id" :value="v.id">{{ v.name }}</option>
                </select>
                <div v-else id="vs1" class="vname">{{ owner.venue.name }}</div>
                <!-- staff: their panel's name -->
                <span v-if="!isOwner" class="rolepill">{{ $t('switch.staff_panel') }}</span>
            </div>
            <nav class="onav">
                <Link v-for="n in nav" :key="n.name" :href="route(n.name)" :aria-current="current(n.name) ? 'page' : undefined">
                    <Icon :name="n.icon" /><span>{{ $t(n.label) }}</span
                    ><span v-if="n.badge" class="nbadge">{{ n.badge }}</span>
                </Link>
            </nav>
            <div class="oside-foot">
                <LangSwitch />
                <!-- the account (photo, Telegram, language); the player app is not for people who run a place -->
                <Link class="olink" :href="route('me.profile')"><Icon name="user" size="sm" />{{ $t('profile.title') }}</Link>
                <!-- admins: their panel -->
                <a v-if="user?.isAdmin" class="olink" href="/admin"><Icon name="settings" size="sm" />{{ $t('switch.admin') }}</a>
                <button v-if="installable()" type="button" class="olink install-item" data-test="install-app" @click="promptInstall"><Icon name="install" size="sm" />{{ $t('pwa.install.button') }}</button>
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
            <Link class="logo-mark" :href="route('owner.start')" :aria-label="$t('owner.brand')">
                <i class="logo-glyph" aria-hidden="true" />
            </Link>
            <select v-if="owner.venues.length > 1" class="input vsel" :value="owner.venue.id" :aria-label="$t('owner.place')" @change="switchVenue">
                <option v-for="v in owner.venues" :key="v.id" :value="v.id">{{ v.name }}</option>
            </select>
            <div v-else class="vname">{{ owner.venue.name }}</div>
            <RequestsBell v-if="may('requests')" compact />
            <Link class="x" :href="route('me.profile')" :title="$t('profile.title')" :aria-label="$t('profile.title')"><Icon name="user" size="sm" /></Link>
            <a v-if="user?.isAdmin" class="x" href="/admin" :title="$t('admin.nav.admin_panel')" :aria-label="$t('admin.nav.admin_panel')"><Icon name="settings" size="sm" /></a>
        </header>
        <main class="omain">
            <!-- computers: the requests waiting for an answer, top right of the page -->
            <div v-if="may('requests')" class="obell"><RequestsBell /></div>
            <slot />
        </main>
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
                <span class="tb-ico"><Icon name="more" size="lg" /><span v-if="moreBadge" class="nbadge">{{ moreBadge }}</span></span
                >{{ $t('owner.nav.more') }}
            </button>
        </nav>
        <!-- phones: the other pages -->
        <div v-if="moreOpen" class="scrim" @click.self="moreOpen = false">
            <div class="sheet" role="dialog" aria-modal="true">
                <nav class="moremenu">
                    <Link v-for="n in more" :key="n.name" :href="route(n.name)" :aria-current="current(n.name) ? 'page' : undefined" @click="moreOpen = false">
                        <Icon :name="n.icon" /><span>{{ $t(n.label) }}</span><span v-if="n.badge" class="nbadge mb">{{ n.badge }}</span>
                    </Link>
                    <!-- the account -->
                    <Link :href="route('me.profile')"><Icon name="user" /><span>{{ $t('profile.title') }}</span></Link>
                    <button v-if="installable()" type="button" class="install-item" data-test="install-app-more" @click="(moreOpen = false), promptInstall()">
                        <Icon name="install" /><span>{{ $t('pwa.install.button') }}</span>
                    </button>
                </nav>
                <a v-if="user?.isAdmin" class="more-admin" href="/admin"><Icon name="settings" /><span>{{ $t('switch.admin') }}</span></a>
            </div>
        </div>
        <QrScanner v-if="scanOpen" @close="scanOpen = false" />
        <OwnerSheets />
        <InstallSheet />
        <Flash />
        <ConfirmDialog />
        <!-- computers: the mini chat at the bottom right (answer players while booking) -->
        <ChatDock v-if="may('messages')" />
    </div>
</template>
