<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { SPORT_ICON } from '@/composables/usePlayer';
import { amd, useShared } from '@/composables/useShared';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import { useLive } from '@/lib/echo';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

// My cards: one per club. Show the club's QR at the desk: they top up, take payments from it, mark trainings,
// see your discount. Each QR works only at its own club. Ratings per sport are here too.
interface Card {
    id: number;
    venue: { slug: string; name: string };
    balance: number;
    discount: number;
    qr: string;
    code: string;
    history: { id: number; amount: number; kind: string; note: string | null; at: string }[];
}
const props = defineProps<{ cards: Card[]; ratings: Record<string, { rating: number; intl_id: string | null }>; open: string | null }>();
const shown = ref<number | null>(props.cards.find((c) => c.venue.slug === props.open)?.id ?? (props.cards.length === 1 ? props.cards[0].id : null));
const big = ref<Card | null>(null);
onMounted(() => {
    if (props.open) document.getElementById('card-' + props.open)?.scrollIntoView({ block: 'start' });
});
// live: a top-up, a payment, a new discount on one of my cards
const { user } = useShared();
let reloadT: number | undefined;
useLive(
    () => (user.value ? `user.${user.value.id}` : null),
    'card.updated',
    () => {
        clearTimeout(reloadT);
        reloadT = window.setTimeout(() => router.reload({ only: ['cards'] }), 300);
    },
    true,
);
const sign = (n: number) => (n > 0 ? '+' : '−') + amd(Math.abs(n));
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('club.my_cards')" />
        <div class="pg">
            <h1 class="page-title">{{ $t('club.my_cards') }}</h1>
            <p class="muted" style="margin: 8px 0 16px">{{ $t('club.my_cards_sub') }}</p>

            <div v-if="Object.keys(ratings).length" class="card pad rts">
                <span v-for="(r, sp) in ratings" :key="sp" class="rt">
                    <span class="em">{{ SPORT_ICON[sp] }}</span>
                    <span><small>{{ $t('club.rating_of', { sport: $t(`common.sports.${sp}`) }) }}</small><b>{{ r.rating }}</b></span>
                    <small v-if="r.intl_id" class="faint">ID {{ r.intl_id }}</small>
                </span>
            </div>

            <article v-for="c in cards" :id="'card-' + c.venue.slug" :key="c.id" class="card ccard">
                <button type="button" class="chead" :aria-expanded="shown === c.id" @click="shown = shown === c.id ? null : c.id">
                    <span class="nm"><b>{{ c.venue.name }}</b><small v-if="c.discount">{{ $t('club.your_discount', { p: c.discount }) }}</small></span>
                    <span class="bal">{{ amd(c.balance) }}</span>
                </button>
                <div v-if="shown === c.id" class="cbody">
                    <!-- white around the code: scanners need the light border -->
                    <button type="button" class="qr" :aria-label="$t('club.show_big')" @click="big = c" v-html="c.qr" />
                    <p class="faint small">{{ $t('club.qr_hint', { venue: c.venue.name }) }}</p>
                    <div v-for="e in c.history" :key="e.id" class="hist">
                        <span>{{ e.at }}</span><b :class="e.amount > 0 ? 'plus' : 'minus'">{{ sign(e.amount) }}</b><small>{{ $t('club.entry.' + e.kind) }}{{ e.note ? ' · ' + e.note : '' }}</small>
                    </div>
                    <Link class="btn ghost sm" :href="route('places.show', c.venue.slug)"><Icon name="pin" size="sm" />{{ c.venue.name }}</Link>
                </div>
            </article>
            <div v-if="!cards.length" class="card pad empty">{{ $t('club.no_cards') }}</div>
        </div>

        <!-- full screen QR for the desk scanner -->
        <div v-if="big" class="bigqr" role="dialog" aria-modal="true" @click="big = null">
            <div class="qr" v-html="big.qr" />
            <b>{{ big.venue.name }}</b>
            <small>{{ $t('club.tap_to_close') }}</small>
        </div>
    </PlayerLayout>
</template>

<style scoped>
.pg { max-width: 640px; margin: 0 auto; padding-top: 18px; }
.rts { display: flex; gap: 18px; flex-wrap: wrap; margin-bottom: 14px; }
.rt { display: flex; align-items: center; gap: 10px; }
.rt .em { font-size: 26px; }
.rt small { display: block; color: var(--muted); font-size: 12px; font-weight: 700; }
.rt b { font-family: var(--display); font-size: 24px; }
.ccard { padding: 0; margin-bottom: 12px; overflow: hidden; }
.chead { width: 100%; display: flex; align-items: center; gap: 12px; padding: 16px; background: none; border: 0; color: var(--ink); text-align: left; font: inherit; }
.chead .nm { flex: 1; display: flex; flex-direction: column; }
.chead .nm b { font-size: 16px; }
.chead .nm small { color: var(--accent); font-weight: 700; font-size: 12.5px; }
.chead .bal { font-family: var(--display); font-size: 22px; }
.cbody { padding: 0 16px 16px; display: flex; flex-direction: column; gap: 8px; align-items: stretch; }
.qr { background: #fff; border-radius: 16px; padding: 12px; width: min(260px, 100%); align-self: center; border: 0; }
.qr :deep(svg) { display: block; width: 100%; height: auto; }
.small { font-size: 12.5px; text-align: center; margin: 0 0 6px; }
.hist { display: grid; grid-template-columns: 82px auto 1fr; gap: 10px; align-items: baseline; padding: 6px 0; font-size: 13px; border-bottom: 1px solid var(--line); }
.hist span { color: var(--faint); font-family: var(--mono); font-size: 12px; }
.hist b { font-family: var(--mono); }
.hist b.plus { color: var(--free); }
.hist b.minus { color: var(--hot); }
.hist small { color: var(--muted); }
.cbody .btn { align-self: flex-start; margin-top: 6px; }
.bigqr { position: fixed; inset: 0; z-index: 200; background: #fff; color: #111; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 24px; }
.bigqr .qr { width: min(80vw, 420px); padding: 0; }
.bigqr small { color: #666; }
</style>
