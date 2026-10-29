<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ChatDock from '@/components/owner/ChatDock.vue';
import PanelToggle from '@/components/owner/PanelToggle.vue';
import RequestsBell from '@/components/owner/RequestsBell.vue';
import { ping } from '@/lib/sound';
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
import { computed, onBeforeUnmount, ref, watch } from 'vue';

// Owner web: sidebar on desktop; top bar + bottom tabs on phones. Hosts the booking sheets for every owner page.
// newBooking: the page offers "+ New booking" (its header button on desktop, this floating button on phones).
// The language switch lives in Settings (G6: no language switch in the header for logged-in users).
const props = defineProps<{ title?: string; newBooking?: FormOpts }>();
const owner = useOwner();
const page = usePage();
const { user } = useShared();
const has = (f: string) => (owner.value.features ?? []).includes(f);
// the club pages show only where the admin switched them on (admin → place → features); some are the owner's only
// (money, all cards, ZoneSpot's fee, team, settings): staff get a short menu for the daily work
const isOwner = computed(() => owner.value.role === 'owner');
const club = computed(() =>
    [
        { name: 'owner.now', label: 'owner.nav.now', icon: 'clock', on: has('wallet') || has('shop') },
        { name: 'owner.players', label: 'owner.nav.players', icon: 'users', on: has('wallet') || has('rating') },
        { name: 'owner.cards', label: 'owner.nav.cards', icon: 'wallet', on: has('wallet') && isOwner.value },
        { name: 'owner.shop', label: 'owner.nav.shop', icon: 'cube', on: has('shop') },
        { name: 'owner.tournaments', label: 'owner.nav.tournaments', icon: 'trophy', on: has('tournaments') },
        { name: 'owner.money', label: 'owner.nav.money', icon: 'chart', on: has('money') && isOwner.value },
    ]
        .filter((n) => n.on)
        .map((n) => ({ ...n, badge: 0 })),
);
const nav = computed(() => [
    // a club starts at the desk ("Now")
    ...club.value.filter((n) => n.name === 'owner.now'),
    { name: 'owner.today', label: 'common.today', icon: 'home', badge: 0 },
    // the requests waiting for an answer (several for the same time: together, the best first)
    { name: 'owner.requests', label: 'owner.nav.requests', icon: 'bell', badge: Math.max(0, owner.value.pending - Object.keys(decisions.hidden).length) },
    { name: 'owner.schedule', label: 'owner.nav.schedule', icon: 'calendar', badge: 0 },
    ...club.value.filter((n) => n.name !== 'owner.now'),
    // the players' messages to the place (and the tournament chats): the chat list, unread on the badge
    {
        name: 'owner.messages',
        label: 'owner.nav.messages',
        icon: 'chat',
        badge: Number((page.props.counts as { messages?: number } | null)?.messages ?? 0),
    },
    // the owner: ZoneSpot's fee (just its %), who works here, the place's settings
    ...(isOwner.value
        ? [
              { name: 'owner.fee', label: 'owner.nav.fee', icon: 'percent', badge: 0 },
              { name: 'owner.team', label: 'owner.nav.team', icon: 'team', badge: 0 },
              { name: 'owner.settings', label: 'owner.nav.settings', icon: 'settings', badge: 0 },
          ]
        : []),
]);
// phones: a club: Now · Schedule · Scan · More (Today and the rest); others: the three tabs as before
const canScan = computed(() => has('wallet'));
const isClub = computed(() => club.value.some((n) => n.name === 'owner.now'));
const tabs = computed(() => (isClub.value ? nav.value.filter((n) => ['owner.now', 'owner.schedule'].includes(n.name)) : club.value.length ? nav.value.slice(0, 2) : nav.value));
const more = computed(() => (club.value.length ? nav.value.filter((n) => !tabs.value.includes(n)) : []));
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
            <Link :href="route('owner.today')"><Logo :sub="$t('owner.brand_short')" /></Link>
            <div class="field vbox">
                <label for="vs1">{{ $t('owner.place') }}</label>
                <select v-if="owner.venues.length > 1" id="vs1" class="input vsel" :value="owner.venue.id" @change="switchVenue">
                    <option v-for="v in owner.venues" :key="v.id" :value="v.id">{{ v.name }}</option>
                </select>
                <div v-else id="vs1" class="vname">{{ owner.venue.name }}</div>
                <!-- the owner: owner panel / staff panel; staff: just their panel's name -->
                <PanelToggle />
                <span v-if="owner.realRole === 'staff'" class="rolepill">{{ $t('switch.staff_panel') }}</span>
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
                <!-- admins: their panel (owner / staff: the toggle at the top) -->
                <a v-if="user?.isAdmin" class="olink" href="/admin"><Icon name="settings" size="sm" />{{ $t('switch.admin') }}</a>
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
            <a v-if="owner.realRole === 'owner' && owner.role === 'staff'" class="staffchip" :href="`${route('switch')}?to=owner`" :title="$t('switch.owner_panel')">{{ $t('switch.staff_short') }} ⇄</a>
            <RequestsBell compact />
            <Link class="x" :href="route('me.profile')" :title="$t('profile.title')" :aria-label="$t('profile.title')"><Icon name="user" size="sm" /></Link>
            <a v-if="user?.isAdmin" class="x" href="/admin" :title="$t('admin.nav.admin_panel')" :aria-label="$t('admin.nav.admin_panel')"><Icon name="settings" size="sm" /></a>
        </header>
        <main class="omain">
            <!-- computers: the requests waiting for an answer, top right of the page -->
            <div class="obell"><RequestsBell /></div>
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
                <span class="tb-ico"><Icon name="more" size="lg" /></span>{{ $t('owner.nav.more') }}
            </button>
        </nav>
        <!-- phones: the other pages -->
        <div v-if="moreOpen" class="scrim" @click.self="moreOpen = false">
            <div class="sheet" role="dialog" aria-modal="true">
                <div style="margin-bottom: 10px"><PanelToggle /></div>
                <nav class="moremenu">
                    <Link v-for="n in more" :key="n.name" :href="route(n.name)" :aria-current="current(n.name) ? 'page' : undefined" @click="moreOpen = false">
                        <Icon :name="n.icon" /><span>{{ $t(n.label) }}</span>
                    </Link>
                    <!-- the account; switching: admin panel / owner / as staff / player app (full page loads) -->
                    <Link :href="route('me.profile')"><Icon name="user" /><span>{{ $t('profile.title') }}</span></Link>
                </nav>
                <a v-if="user?.isAdmin" class="more-admin" href="/admin"><Icon name="settings" /><span>{{ $t('switch.admin') }}</span></a>
            </div>
        </div>
        <QrScanner v-if="scanOpen" @close="scanOpen = false" />
        <OwnerSheets />
        <Flash />
        <ConfirmDialog />
        <!-- computers: the mini chat at the bottom right (answer players while booking) -->
        <ChatDock />
    </div>
</template>
