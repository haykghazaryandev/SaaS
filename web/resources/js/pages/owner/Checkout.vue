<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import QrScanner from '@/components/owner/QrScanner.vue';
import ShopSheet from '@/components/owner/ShopSheet.vue';
import { ask } from '@/composables/useConfirm';
import { relDay, useOwner, type BookingRow } from '@/composables/useOwner';
import { amd, hhmm } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { trans } from '@/lib/i18n';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// The check of a game: the time + what they took (a cola) = the total,
// then the money: from the player's card (we know it from the booking), in cash, or part each (1 450 + 50).
// A booking made by phone without an account: scan the player's card to find it.
interface Bill {
    /** the desk started the game: only then is there a bill */
    started: boolean;
    minutes: number;
    running: boolean;
    stopped: boolean;
    time: number;
    items: { id: number; product_id: number | null; name: string; qty: number; price_each: number; sum: number }[];
    itemsTotal: number;
    total: number;
    card: { code: string; balance: number; cashback: number } | null;
    paid: boolean;
}
const props = defineProps<{
    booking: BookingRow & { field: string; purpose?: string; paid?: boolean; player: { id: number; name: string | null; phone: string | null } | null };
    bill: Bill;
    wallet: boolean;
    products: { id: number; name: string; price: number; photo: string | null }[];
}>();
const owner = useOwner();
const opts = { preserveScroll: true, preserveState: true };
const scan = ref(false);
const canCard = computed(() => props.wallet && !!props.bill.card && props.bill.card.balance > 0);
// how they pay: cash by default; the card (if they have one with money) or part card, part cash, when the owner picks it
type How = 'cash' | 'card' | 'both';
const how = ref<How>('cash');
const cardCovers = computed(() => canCard.value && props.bill.card!.balance >= props.bill.total);
const fromCard = ref(0);
function pick(h: How) {
    how.value = h;
    // card + cash starts half and half (rounded to 100 ֏); either box can be changed, the other follows
    const half = Math.round(props.bill.total / 200) * 100;
    fromCard.value = h === 'cash' ? 0 : Math.min(props.bill.card!.balance, h === 'both' ? half : props.bill.total);
}
const setCash = (e: Event) => (fromCard.value = Math.max(0, props.bill.total - (Number((e.target as HTMLInputElement).value) || 0)));
watch(
    () => [props.bill.total, props.bill.card?.balance],
    () => {
        if (!canCard.value || (how.value === 'card' && !cardCovers.value)) how.value = 'cash';
        pick(how.value);
    },
    { immediate: true },
);
const cash = computed(() => Math.max(0, props.bill.total - (fromCard.value || 0)));
const tooMuch = computed(() => how.value !== 'cash' && (fromCard.value > props.bill.card!.balance || fromCard.value > props.bill.total));

const shopOpen = ref(false);
const onCode = (code: string) => router.post(route('owner.bookings.scan', props.booking.id), { code }, opts);
// no camera at the desk: find the card by the player's name or phone
const find = ref('');
const found = ref<{ code: string; name: string | null; phone: string | null; balance: number }[]>([]);
let findT: number | undefined;
watch(find, (q) => {
    clearTimeout(findT);
    if (q.trim().length < 2) return (found.value = []);
    findT = window.setTimeout(async () => {
        const r = await fetch(route('owner.bookings.cards', { booking: props.booking.id, q: q.trim() }), { headers: { Accept: 'application/json' } });
        found.value = r.ok ? await r.json() : [];
    }, 250);
});
function pickCard(code: string) {
    find.value = '';
    found.value = [];
    onCode(code);
}
async function startGame() {
    if (await ask({ title: trans('owner.club.ask_start', { name: props.booking.team || props.booking.name || props.booking.field }), text: trans('owner.club.ask_start_text'), ok: trans('owner.club.start_game') }))
        router.post(route('owner.bookings.start', props.booking.id), {}, opts);
}
async function stop() {
    if (
        await ask({
            title: trans('owner.club.ask_stop', { name: props.booking.team || props.booking.name || props.booking.field }),
            text: trans('owner.club.ask_stop_text', { a: amd(props.bill.total) }),
            ok: trans('owner.club.stop_game'),
            danger: true,
        })
    )
        router.post(route('owner.bookings.stop', props.booking.id));
}
// the money: say exactly what is taken (card / cash) and ask
async function pay() {
    const card = how.value === 'cash' ? 0 : fromCard.value || 0;
    const money = how.value === 'cash' ? props.bill.total : cash.value;
    const title =
        card > 0 && money > 0
            ? trans('owner.club.take_split', { a: amd(card), b: amd(money) })
            : card > 0
              ? trans('owner.club.take_card', { a: amd(card) })
              : trans('owner.club.take_cash', { a: amd(money) });
    const lines = [
        card > 0 && props.bill.card ? trans('owner.club.ask_card_after', { a: amd(props.bill.card.balance - card) }) : '',
        money > 0 ? trans('owner.club.ask_cash_hands') : '',
        props.bill.card?.cashback
            ? trans('owner.club.cashback_note', { p: props.bill.card.cashback }) +
              ' +' +
              amd(Math.floor((props.bill.total * props.bill.card.cashback) / 100))
            : '',
    ].filter(Boolean);
    if (await ask({ title: title + '?', text: lines.join('\n'), ok: trans('owner.club.ask_take') }))
        router.post(route('owner.bookings.pay', props.booking.id), { card, cash: money });
}
</script>

<template>
    <OwnerLayout :title="$t('owner.club.bill')">
        <Link v-if="(owner.features ?? []).includes('wallet') || (owner.features ?? []).includes('shop')" class="backlink" :href="route('owner.now')"
            ><Icon name="back" size="sm" />{{ $t('owner.nav.now') }}</Link
        >
        <Link v-else class="backlink" :href="route('owner.schedule', { date: booking.date })"><Icon name="back" size="sm" />{{ $t('owner.nav.schedule') }}</Link>
        <section class="card panel">
            <div class="kicker">{{ owner.venue.name }} · {{ booking.field }}</div>
            <h1 style="margin: 4px 0; font-size: 24px">
                {{ booking.team || booking.name || '—' }}<span v-if="booking.purpose === 'training'" class="pill" style="margin-left: 8px">{{ $t('club.training') }}</span>
            </h1>
            <p class="muted" style="margin: 0">
                {{ relDay(booking.date, owner.today) }} · {{ hhmm(booking.start) }}–{{ hhmm(booking.end) }}
                · <template v-if="booking.player">{{ booking.player.name }} {{ booking.player.phone }}</template
                ><template v-else>{{ $t('owner.club.no_account') }}</template>
            </p>
        </section>

        <!-- not started yet: no bill, no money (the booked team: "Start game" when they are here) -->
        <section v-if="!bill.started && !bill.paid" class="card panel notstarted" data-test="not-started">
            <Icon name="clock" />
            <div>
                <b>{{ $t('owner.club.not_started') }}</b>
                <p class="muted">{{ $t('owner.club.not_started_text') }}</p>
                <button v-if="booking.can_start" type="button" class="btn" data-test="start-game" @click="startGame"><Icon name="check" size="sm" />{{ $t('owner.club.start_game') }}</button>
            </div>
        </section>
        <div v-else class="cgrid">
            <section class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.club.bill') }}</h2></div>
                <div class="bline">
                    <span>{{ booking.purpose === 'training' ? $t('club.training') : $t('owner.club.time') }} · {{ $t('owner.club.min_n', { n: bill.minutes }) }}</span><b>{{ amd(bill.time) }}</b>
                </div>
                <!-- the shop: one line; a tap opens the products (photo, price, − / +) -->
                <button type="button" class="bline shopln" :disabled="bill.paid" data-test="shop" @click="shopOpen = true">
                    <span
                        ><Icon name="cube" size="sm" /> {{ $t('owner.club.shop') }}<small v-if="bill.items.length" class="faint">
                            · {{ bill.items.map((i) => `${i.qty} × ${i.name}`).join(', ') }}</small
                        ></span
                    ><b>{{ amd(bill.itemsTotal) }}</b><Icon v-if="!bill.paid" name="plus" size="sm" class="plus" />
                </button>
                <div class="bline total">
                    <span>{{ bill.running ? $t('owner.club.if_stop_now') : $t('player.place.total') }}</span><b data-test="total">{{ amd(bill.total) }}</b>
                </div>
                <button v-if="bill.running && !bill.paid" type="button" class="btn ghost block" data-test="stop" @click="stop"><Icon name="check" size="sm" />{{ $t('owner.club.stop_game') }}</button>

            </section>

            <section class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.club.payment') }}</h2></div>
                <div v-if="bill.paid" class="paid"><Icon name="check" />{{ $t('owner.club.paid') }}</div>
                <template v-else>
                    <!-- the card: only after a scan -->
                    <!-- the player's card: known from the booking; a phone-only booking finds it by a scan -->
                    <div v-if="wallet" class="cardrow">
                        <Icon name="wallet" />
                        <span class="w">
                            <b>{{ bill.card ? $t('owner.club.card_balance', { a: amd(bill.card.balance) }) : $t('owner.club.no_card_short') }}</b>
                            <small v-if="!bill.card && !booking.player">{{ $t('owner.club.find_card_why') }}</small>
                        </span>
                        <button v-if="!bill.card && !booking.player" type="button" class="btn sm" data-test="scan-bill" @click="scan = true"><Icon name="qr" size="sm" />{{ $t('owner.club.scan') }}</button>
                    </div>
                    <!-- no card on this bill yet: scan it (a phone or a desk camera) or find it by name / phone -->
                    <div v-if="wallet && !bill.card && !booking.player" class="findcard">
                        <input v-model="find" class="input" type="search" :placeholder="$t('owner.club.find_card_search')" data-test="find-card" />
                        <button v-for="c in found" :key="c.code" type="button" class="frow" :data-test="`found-${c.code}`" @click="pickCard(c.code)">
                            <span class="w"><b>{{ c.name || '—' }}</b><small>{{ c.phone }}</small></span><b>{{ amd(c.balance) }}</b>
                        </button>
                        <small v-if="find.trim().length >= 2 && !found.length" class="faint">{{ $t('owner.club.no_cards_found') }}</small>
                    </div>
                    <div v-if="canCard" class="seg how" role="group" :aria-label="$t('owner.club.payment')">
                        <button type="button" :aria-pressed="how === 'cash'" data-test="how-cash" @click="pick('cash')">{{ $t('owner.club.cash') }}</button>
                        <button type="button" :aria-pressed="how === 'card'" :disabled="!cardCovers" data-test="how-card" @click="pick('card')">
                            {{ $t('owner.club.from_card') }}
                        </button>
                        <button type="button" :aria-pressed="how === 'both'" data-test="how-both" @click="pick('both')">{{ $t('owner.club.card_and_cash') }}</button>
                    </div>
                    <div v-if="canCard && how === 'both'" class="split">
                        <label class="field"
                            ><span>{{ $t('owner.club.from_card') }}</span
                            ><span class="money"><input v-model.number="fromCard" class="input" type="number" min="0" :max="Math.min(bill.card!.balance, bill.total)" data-test="from-card" /><span>֏</span></span></label
                        >
                        <label class="field"><span>{{ $t('owner.club.cash') }}</span><span class="money"><input :value="cash" class="input" type="number" min="0" :max="bill.total" data-test="cash" @input="setCash" /><span>֏</span></span></label>
                    </div>
                    <!-- the card's cashback level: this much of the check (card or cash) comes back onto it -->
                    <p v-if="bill.card?.cashback" class="faint" style="font-size: 12.5px; margin: 0">
                        {{ $t('owner.club.cashback_note', { p: bill.card.cashback }) }} +{{ amd(Math.floor((bill.total * bill.card.cashback) / 100)) }}
                    </p>
                    <p v-if="tooMuch" class="err">{{ $t('booking.error.low_balance') }}</p>
                    <button type="button" class="btn block big" :disabled="tooMuch" data-test="take-payment" @click="pay">
                        <Icon name="check" size="sm" />
                        <template v-if="how !== 'cash' && fromCard > 0 && cash > 0">{{ $t('owner.club.take_split', { a: amd(fromCard), b: amd(cash) }) }}</template>
                        <template v-else-if="how !== 'cash' && fromCard > 0">{{ $t('owner.club.take_card', { a: amd(fromCard) }) }}</template>
                        <template v-else>{{ $t('owner.club.take_cash', { a: amd(bill.total) }) }}</template>
                    </button>
                </template>
            </section>
        </div>
        <QrScanner v-if="scan" pick @code="onCode" @close="scan = false" />
        <ShopSheet v-if="shopOpen" :booking-id="booking.id" :items="bill.items" :products="products" :total="bill.itemsTotal" @close="shopOpen = false" />
    </OwnerLayout>
</template>

<style scoped>
.notstarted { display: flex; gap: 14px; align-items: flex-start; margin-top: 14px; }
.notstarted p { margin: 4px 0 12px; }
.cgrid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; align-items: start; margin-top: 14px; }
.cgrid .panel { margin: 0; }
.bline { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--line); }
.bline span { flex: 1; }
.bline b { font-family: var(--mono); }
.bline .x { width: 28px; height: 28px; border: 0; border-radius: 8px; background: none; color: var(--muted); display: grid; place-items: center; }
.bline.disc { color: var(--accent); }
.bline.total { border-bottom: 0; font-size: 18px; }
.bline.total b { font-size: 22px; color: var(--accent); }
.shopln { width: 100%; border: 0; background: none; color: var(--ink); font: inherit; text-align: left; cursor: pointer; }
.shopln small { font-size: 12.5px; }
.shopln .plus { color: var(--accent); }
.cardrow { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 12px; background: var(--surface-2); margin-bottom: 12px; }
.cardrow .w { flex: 1; display: flex; flex-direction: column; }
.cardrow small { color: var(--muted); font-size: 12.5px; }
.findcard { display: grid; gap: 6px; margin-bottom: 12px; }
.frow { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 12px; border: 1px solid var(--line-2); background: var(--surface-2); color: var(--ink); text-align: left; font: inherit; }
.frow .w { flex: 1; display: flex; flex-direction: column; }
.frow small { color: var(--muted); font-size: 12.5px; }
.how { display: flex; width: 100%; margin-bottom: 12px; }
.how button { flex: 1; justify-content: center; }
.how button:disabled { opacity: 0.45; }
.split { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
.split .field > span:first-child { display: block; font-size: 12.5px; font-weight: 800; color: var(--muted); margin-bottom: 5px; }
.big { padding: 14px; font-size: 16px; }
.paid { display: flex; gap: 8px; align-items: center; padding: 12px 14px; border-radius: 12px; background: var(--free-soft); color: var(--free); font-weight: 800; }
</style>
