<script setup lang="ts">
import '../../css/owner.css';

import Avatar from '@/components/Avatar.vue';
import Flash from '@/components/Flash.vue';
import Icon from '@/components/Icon.vue';
import LangSwitch from '@/components/LangSwitch.vue';
import Logo from '@/components/Logo.vue';
import OwnerSheets from '@/components/owner/OwnerSheets.vue';
import { decisions, openForm, sheets, useOwner, type FormOpts } from '@/composables/useOwner';
import { useShared } from '@/composables/useShared';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount } from 'vue';

// Owner web: sidebar on desktop; top bar + bottom tabs on phones. Hosts the booking sheets for every owner page.
// newBooking: the page offers "+ New booking" (its header button on desktop, this floating button on phones).
// The language switch lives in Settings (G6: no language switch in the header for logged-in users).
const props = defineProps<{ title?: string; newBooking?: FormOpts }>();
const owner = useOwner();
const { user } = useShared();
const nav = computed(() => [
    { name: 'owner.today', label: 'owner.nav.today', icon: 'home', badge: Math.max(0, owner.value.pending - Object.keys(decisions.hidden).length) },
    { name: 'owner.schedule', label: 'owner.nav.schedule', icon: 'calendar', badge: 0 },
    { name: 'owner.settings', label: 'owner.nav.settings', icon: 'settings', badge: 0 },
]);
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
                <a class="olink" :href="route('home')"><Icon name="share" size="sm" />{{ $t('owner.player_site') }}</a>
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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                    <circle cx="12" cy="12" r="8.5" />
                    <path d="M12 3.5v17M3.5 12h17" />
                </svg>
            </Link>
            <select v-if="owner.venues.length > 1" class="input vsel" :value="owner.venue.id" :aria-label="$t('owner.place')" @change="switchVenue">
                <option v-for="v in owner.venues" :key="v.id" :value="v.id">{{ v.name }}</option>
            </select>
            <div v-else class="vname">{{ owner.venue.name }}</div>
            <LangSwitch />
            <a class="x" :href="route('home')" :title="$t('owner.player_site')" :aria-label="$t('owner.player_site')"
                ><Icon name="share" size="sm"
            /></a>
        </header>
        <main class="omain"><slot /></main>
        <button v-if="props.newBooking" type="button" class="ofab" data-test="fab" @click="openForm(props.newBooking)">
            <Icon name="plus" /><span>{{ $t('owner.new_booking') }}</span>
        </button>
        <nav class="tabbar">
            <Link v-for="n in nav" :key="n.name" :href="route(n.name)" :aria-current="current(n.name) ? 'page' : undefined">
                <span class="tb-ico"
                    ><Icon :name="n.icon" size="lg" /><span v-if="n.badge" class="nbadge">{{ n.badge }}</span></span
                >{{ $t(n.label) }}
            </Link>
        </nav>
        <OwnerSheets />
        <Flash />
    </div>
</template>
