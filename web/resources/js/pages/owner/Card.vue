<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import { openBooking, useOwner } from '@/composables/useOwner';
import { SPORT_ICON } from '@/composables/usePlayer';
import { amd, hhmm } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

// A player's card at this place, opened by scanning their QR: money (top up, pay, discount), today's bookings
// and trainings with "paid", a quick shop sale, the rating in the place's sport, and the card's history.
interface Entry {
    id: number;
    amount: number;
    kind: string;
    note: string | null;
    by: string | null;
    at: string;
}
const props = defineProps<{
    card: { code: string; balance: number; discount: number; note: string | null };
    player: { id: number; name: string | null; phone: string | null; tel: string | null; avatar: string | null; no_shows: number };
    sport: string;
    rating: { rating: number | null; intl_id: string | null; history: { old: number | null; new: number | null; note: string | null; where: string | null; at: string }[] };
    cashback: number;
    bookings: { id: number; date: string; start: number; end: number; field: string; price: number; status: string; paid: string | null }[];
    trainings: { id: number; title: string; coach: string | null; start: string; end: string; price: number; today: boolean; visited: boolean }[];
    products: { id: number; name: string; price: number; photo: string | null }[];
    history: Entry[];
}>();
const owner = useOwner();
const has = (f: string) => (owner.value.features ?? []).includes(f);
const isOwner = computed(() => owner.value.role === 'owner');
const opts = { preserveScroll: true, preserveState: true };
const r = (name: string, extra: Record<string, unknown> = {}) => route(name, { code: props.card.code, ...extra });
const num = (v: string | number) => Number(String(v ?? '').replace(/\D/g, '')) || 0;

const money = reactive({ topup: '', pay: '', payNote: '', discount: String(props.card.discount) });
function topUp() {
    if (num(money.topup)) router.post(r('owner.cards.topup'), { amount: num(money.topup) }, { ...opts, onSuccess: () => (money.topup = '') });
}
function pay(method: 'card' | 'cash') {
    if (num(money.pay)) router.post(r('owner.cards.charge'), { amount: num(money.pay), method, note: money.payNote || null }, { ...opts, onSuccess: () => ((money.pay = ''), (money.payNote = '')) });
}
function saveDiscount() {
    router.post(r('owner.cards.discount'), { discount: num(money.discount) }, opts);
}
const rate = reactive({ rating: props.rating.rating != null ? String(props.rating.rating) : '', intl: props.rating.intl_id ?? '', note: '' });
function saveRating() {
    router.post(r('owner.cards.rating'), { rating: rate.rating === '' ? null : num(rate.rating), intl_id: rate.intl || null, note: rate.note || null }, { ...opts, onSuccess: () => (rate.note = '') });
}
const payBooking = (id: number, method: string) => router.post(route('owner.bookings.pay', id), { method }, opts);
const visit = (id: number, method: string | null) => router.post(r('owner.cards.visit', { training: id }), { method }, opts);
const qty = reactive<Record<number, number>>({});
const sell = (id: number, method: string) => router.post(r('owner.cards.sell', { product: id }), { qty: qty[id] || 1, method }, { ...opts, onSuccess: () => (qty[id] = 1) });
const sign = (n: number) => (n > 0 ? '+' : '−') + amd(Math.abs(n));
</script>

<template>
    <OwnerLayout :title="player.name || $t('owner.nav.players')">
        <Link class="backlink" :href="route('owner.players')"><Icon name="back" size="sm" />{{ $t('owner.nav.players') }}</Link>
        <section class="card panel who">
            <Avatar :id="player.id" :name="player.name" :src="player.avatar" size="lg" />
            <div>
                <h1 style="margin: 0; font-size: 24px">{{ player.name || '—' }}</h1>
                <a v-if="player.tel" class="muted" :href="`tel:${player.tel}`">{{ player.phone }}</a>
                <div class="row" style="gap: 6px; margin-top: 6px; flex-wrap: wrap">
                    <span v-if="has('rating') && rating.rating != null" class="pill lime">{{ SPORT_ICON[sport] }} {{ $t('club.rating') }} {{ rating.rating }}</span>
                    <span v-if="has('wallet') && card.discount" class="pill">−{{ card.discount }}%</span>
                    <span v-if="player.no_shows" class="pill hot">{{ $t('owner.booking.no_shows', { n: player.no_shows }) }}</span>
                </div>
            </div>
            <div v-if="has('wallet')" class="bal">
                <small>{{ $t('owner.club.balance') }}</small><b data-test="balance">{{ amd(card.balance) }}</b>
            </div>
        </section>

        <div class="cgrid">
            <!-- money: top up, take a payment, the discount -->
            <section v-if="has('wallet')" class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.club.money') }}</h2></div>
                <label class="lbl">{{ $t('owner.club.topup') }}</label>
                <form class="row" style="gap: 8px" @submit.prevent="topUp">
                    <span class="money" style="flex: 1"><input v-model="money.topup" class="input" inputmode="numeric" data-test="topup" /><span>֏</span></span>
                    <button class="btn"><Icon name="plus" size="sm" />{{ $t('owner.club.topup_btn') }}</button>
                </form>
                <label class="lbl">{{ $t('owner.club.take') }}</label>
                <div class="row" style="gap: 8px">
                    <span class="money" style="flex: 1"><input v-model="money.pay" class="input" inputmode="numeric" data-test="pay" /><span>֏</span></span>
                    <input v-model="money.payNote" class="input" :placeholder="$t('owner.club.what_ph')" style="flex: 1" />
                </div>
                <div class="row" style="gap: 8px; margin-top: 8px">
                    <button type="button" class="btn sm" :disabled="!num(money.pay)" data-test="pay-card" @click="pay('card')"><Icon name="wallet" size="sm" />{{ $t('owner.club.from_card') }}</button>
                    <button type="button" class="btn ghost sm" :disabled="!num(money.pay)" @click="pay('cash')">{{ $t('owner.club.cash') }}</button>
                </div>
                <p v-if="card.discount || cashback" class="faint small">
                    <template v-if="card.discount">{{ $t('owner.club.discount_note', { p: card.discount }) }} </template>
                    <template v-if="cashback">{{ $t('owner.club.cashback_note', { p: cashback }) }}</template>
                </p>
                <label class="lbl">{{ $t('owner.club.discount') }}</label>
                <form class="row" style="gap: 8px" @submit.prevent="saveDiscount">
                    <span class="money" style="width: 120px"><input v-model="money.discount" class="input" inputmode="numeric" /><span>%</span></span>
                    <button class="btn ghost sm">{{ $t('common.save') }}</button>
                </form>
            </section>

            <!-- today's bookings of the player: paid by card or cash -->
            <section v-if="bookings.length" class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.club.bookings') }}</h2></div>
                <div v-for="b in bookings" :key="b.id" class="line">
                    <button type="button" class="lk" @click="openBooking(b.id)">
                        <b>{{ b.date.slice(8) }}.{{ b.date.slice(5, 7) }} {{ hhmm(b.start) }}–{{ hhmm(b.end) }}</b><small>{{ b.field }} · {{ amd(b.price) }}</small>
                    </button>
                    <span v-if="b.paid" class="pill green">{{ $t('owner.club.paid_' + b.paid) }}</span>
                    <template v-else-if="b.status !== 'pending'">
                        <button v-if="has('wallet')" type="button" class="btn sm" @click="payBooking(b.id, 'card')">{{ $t('owner.club.from_card') }}</button>
                        <button type="button" class="btn ghost sm" @click="payBooking(b.id, 'cash')">{{ $t('owner.club.cash') }}</button>
                    </template>
                </div>
            </section>

            <!-- trainings: "came today" with the payment -->
            <section v-if="trainings.length" class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.nav.trainings') }}</h2></div>
                <div v-for="t in trainings" :key="t.id" class="line">
                    <span class="lk"><b>{{ t.title }}</b><small>{{ t.start }}–{{ t.end }}{{ t.coach ? ' · ' + t.coach : '' }} · {{ amd(t.price) }}</small></span>
                    <span v-if="t.visited" class="pill green">{{ $t('owner.club.came') }}</span>
                    <template v-else>
                        <button v-if="has('wallet')" type="button" class="btn sm" @click="visit(t.id, 'card')">{{ $t('owner.club.came_card') }}</button>
                        <button type="button" class="btn ghost sm" @click="visit(t.id, 'cash')">{{ $t('owner.club.came_cash') }}</button>
                    </template>
                </div>
            </section>

            <!-- shop: a quick sale to this player -->
            <section v-if="products.length" class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.nav.shop') }}</h2></div>
                <div v-for="p in products" :key="p.id" class="line">
                    <img v-if="p.photo" :src="p.photo" alt="" class="thumb" />
                    <span class="lk"><b>{{ p.name }}</b><small>{{ amd(p.price) }}</small></span>
                    <input v-model.number="qty[p.id]" class="input qty" type="number" min="1" max="100" placeholder="1" :aria-label="$t('owner.club.qty')" />
                    <button v-if="has('wallet')" type="button" class="btn sm" @click="sell(p.id, 'card')">{{ $t('owner.club.from_card') }}</button>
                    <button type="button" class="btn ghost sm" @click="sell(p.id, 'cash')">{{ $t('owner.club.cash') }}</button>
                </div>
            </section>

            <!-- rating in the place's sport (the player's football rating is separate) -->
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

            <!-- the card's history -->
            <section v-if="has('wallet')" class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.club.history') }}</h2></div>
                <div v-for="e in history" :key="e.id" class="hist">
                    <span>{{ e.at }}</span><b :class="e.amount > 0 ? 'plus' : 'minus'">{{ sign(e.amount) }}</b
                    ><small>{{ $t('club.entry.' + e.kind) }}{{ e.note ? ' · ' + e.note : '' }}{{ e.by ? ' · ' + e.by : '' }}</small>
                </div>
                <p v-if="!history.length" class="faint small">{{ $t('owner.club.no_history') }}</p>
                <p v-if="isOwner" class="faint small" style="margin-top: 10px">{{ $t('owner.club.adjust_hint') }}</p>
            </section>
        </div>
    </OwnerLayout>
</template>

<style scoped>
.who { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
.who > div:nth-child(2) { flex: 1; min-width: 180px; }
.bal { text-align: right; }
.bal small { display: block; color: var(--muted); font-size: 12.5px; font-weight: 700; }
.bal b { font-family: var(--display); font-size: 30px; }
.cgrid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; align-items: start; margin-top: 14px; }
.cgrid .panel { margin: 0; }
.lbl { display: block; font-size: 12.5px; font-weight: 800; color: var(--muted); margin: 12px 0 6px; }
.small { font-size: 12.5px; margin: 8px 0 0; }
.line { display: flex; align-items: center; gap: 8px; padding: 9px 0; border-bottom: 1px solid var(--line); }
.line:last-child { border-bottom: 0; }
.line .lk { flex: 1; min-width: 0; display: flex; flex-direction: column; text-align: left; background: none; border: 0; color: var(--ink); padding: 0; font: inherit; }
.line .lk small { color: var(--muted); font-size: 12.5px; }
.line .thumb { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; }
.line .qty { width: 58px; padding: 6px 8px; }
.hist { display: grid; grid-template-columns: 82px auto 1fr; gap: 10px; align-items: baseline; padding: 6px 0; font-size: 13px; border-bottom: 1px solid var(--line); }
.hist span { color: var(--faint); font-family: var(--mono); font-size: 12px; }
.hist b { font-family: var(--mono); }
.hist b.plus { color: var(--free); }
.hist b.minus { color: var(--hot); }
.hist small { color: var(--muted); }
</style>
