<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import { openBooking, pillOf, useOwner, type BookingRow } from '@/composables/useOwner';
import { SPORT_ICON } from '@/composables/usePlayer';
import { amd, hhmm } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

// A player of the place: their bookings here (with what they paid), the card (look; money after a scan),
// and the rating in the place's sport (set here, no scan needed: it is not money).
const props = defineProps<{
    player: { id: number; name: string | null; phone: string | null; tel: string | null; avatar: string | null; no_shows: number };
    sport: string;
    rating: { rating: number | null; intl_id: string | null; history: { old: number | null; new: number | null; note: string | null; where: string | null; at: string }[] };
    card: { code: string; balance: number; cashback: number } | null;
    bookings: (BookingRow & { field: string; purpose?: string; paid?: boolean })[];
}>();
const owner = useOwner();
const has = (f: string) => (owner.value.features ?? []).includes(f);
const rate = reactive({ rating: props.rating.rating != null ? String(props.rating.rating) : '', intl: props.rating.intl_id ?? '', note: '' });
function saveRating() {
    const r = rate.rating.replace(/\D/g, '');
    router.post(route('owner.players.rating', props.player.id), { rating: r === '' ? null : Number(r), intl_id: rate.intl || null, note: rate.note || null }, {
        preserveScroll: true,
        onSuccess: () => (rate.note = ''),
    });
}
const makeCard = () => router.post(route('owner.cards.store'), { user_id: props.player.id });
const fmtDate = (d: string) => `${d.slice(8)}.${d.slice(5, 7)}`;
</script>

<template>
    <OwnerLayout :title="player.name || $t('owner.nav.our_players')">
        <Link class="backlink" :href="route('owner.players')"><Icon name="back" size="sm" />{{ $t('owner.nav.our_players') }}</Link>
        <section class="card panel who">
            <Avatar :id="player.id" :name="player.name" :src="player.avatar" size="lg" />
            <div style="flex: 1; min-width: 180px">
                <h1 style="margin: 0; font-size: 24px">{{ player.name || '—' }}</h1>
                <a v-if="player.tel" class="muted" :href="`tel:${player.tel}`">{{ player.phone }}</a>
                <div class="row" style="gap: 6px; margin-top: 6px; flex-wrap: wrap">
                    <span v-if="rating.rating != null" class="pill lime">{{ SPORT_ICON[sport] }} {{ $t('club.rating') }} {{ rating.rating }}</span>
                    <span v-if="player.no_shows" class="pill hot">{{ $t('owner.booking.no_shows', { n: player.no_shows }) }}</span>
                </div>
            </div>
            <div v-if="has('wallet')" class="cardbox">
                <template v-if="card">
                    <small>{{ $t('owner.club.balance') }}</small><b>{{ amd(card.balance) }}</b>
                    <Link class="btn ghost sm" :href="route('owner.cards.show', card.code)"><Icon name="wallet" size="sm" />{{ $t('owner.club.open_card') }}</Link>
                </template>
                <button v-else type="button" class="btn ghost sm" @click="makeCard"><Icon name="plus" size="sm" />{{ $t('owner.club.make_card') }}</button>
            </div>
        </section>

        <div class="cgrid">
            <section v-if="has('rating')" class="card panel">
                <div class="sec-h"><h2>{{ SPORT_ICON[sport] }} {{ $t('club.rating_of', { sport: $t(`common.sports.${sport}`) }) }}</h2></div>
                <form class="fgrid" @submit.prevent="saveRating">
                    <div class="field"><label>{{ $t('club.rating') }}</label><input v-model="rate.rating" class="input mono" inputmode="numeric" data-test="rating" /></div>
                    <div class="field"><label>{{ $t('owner.club.intl_id') }}</label><input v-model="rate.intl" class="input" /></div>
                    <div class="field" style="grid-column: 1 / -1"><label>{{ $t('owner.club.rating_note') }}</label><input v-model="rate.note" class="input" :placeholder="$t('owner.club.rating_note_ph')" /></div>
                    <button class="btn" style="grid-column: 1 / -1">{{ $t('common.save') }}</button>
                </form>
                <div v-for="(h, i) in rating.history" :key="i" class="hist">
                    <span>{{ h.at }}</span><b>{{ h.old ?? '—' }} → {{ h.new ?? '—' }}</b><small>{{ h.note }}{{ h.where ? ' · ' + h.where : '' }}</small>
                </div>
            </section>

            <section class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.club.bookings') }}</h2></div>
                <button v-for="b in bookings" :key="b.id" type="button" class="line" @click="openBooking(b.id)">
                    <span class="lk"
                        ><b>{{ fmtDate(b.date) }} {{ hhmm(b.start) }}–{{ hhmm(b.end) }}<span v-if="b.purpose === 'training'" class="pill" style="margin-left: 6px">{{ $t('club.training') }}</span></b
                        ><small>{{ b.field }} · {{ amd(b.price) }}</small></span
                    >
                    <span v-if="b.paid" class="pill green">{{ $t('owner.club.paid') }}</span>
                    <span v-else :class="['pill', pillOf(b).cls]">{{ pillOf(b).label }}</span>
                </button>
                <p v-if="!bookings.length" class="faint">{{ $t('owner.club.no_history') }}</p>
            </section>
        </div>
    </OwnerLayout>
</template>

<style scoped>
.who { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
.cardbox { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.cardbox small { color: var(--muted); font-size: 12.5px; font-weight: 700; }
.cardbox b { font-family: var(--display); font-size: 26px; }
.cgrid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; align-items: start; margin-top: 14px; }
.cgrid .panel { margin: 0; }
.line { width: 100%; display: flex; align-items: center; gap: 8px; padding: 9px 0; border: 0; border-bottom: 1px solid var(--line); background: none; color: var(--ink); text-align: left; font: inherit; }
.line .lk { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.line small { color: var(--muted); font-size: 12.5px; }
.hist { display: grid; grid-template-columns: 82px auto 1fr; gap: 10px; align-items: baseline; padding: 6px 0; font-size: 13px; border-bottom: 1px solid var(--line); }
.hist span { color: var(--faint); font-family: var(--mono); font-size: 12px; }
.hist b { font-family: var(--mono); }
.hist small { color: var(--muted); }
</style>
