<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import QrScanner from '@/components/owner/QrScanner.vue';
import ShopSheet from '@/components/owner/ShopSheet.vue';
import { relDay, useOwner, type BookingRow } from '@/composables/useOwner';
import { amd, hhmm } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// The check of a game: the time (full price, the player's discount) + what they took (a cola) = the total,
// then the money: from the player's card (we know it from the booking), in cash, or part each (1 450 + 50).
// A booking made by phone without an account: scan the player's card to find it.
interface Bill {
    minutes: number;
    running: boolean;
    stopped: boolean;
    timeFull: number;
    timeDiscount: number;
    time: number;
    items: { id: number; product_id: number | null; name: string; qty: number; price_each: number; sum: number }[];
    itemsTotal: number;
    itemsDiscount: number;
    discountPct: number;
    total: number;
    card: { code: string; balance: number } | null;
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
// from the card: as much as the balance allows, the rest cash (both editable)
const fromCard = ref(0);
const reset = () => (fromCard.value = canCard.value ? Math.min(props.bill.card!.balance, props.bill.total) : 0);
watch(() => [props.bill.total, props.bill.card?.balance], reset, { immediate: true });
const cash = computed(() => Math.max(0, props.bill.total - (fromCard.value || 0)));
const tooMuch = computed(() => canCard.value && (fromCard.value > props.bill.card!.balance || fromCard.value > props.bill.total));

const shopOpen = ref(false);
const onCode = (code: string) => router.post(route('owner.bookings.scan', props.booking.id), { code }, opts);
const stop = () => router.post(route('owner.bookings.stop', props.booking.id));
function pay() {
    router.post(route('owner.bookings.pay', props.booking.id), { card: canCard.value ? fromCard.value || 0 : 0, cash: cash.value });
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

        <div class="cgrid">
            <section class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.club.bill') }}</h2></div>
                <div class="bline">
                    <span>{{ booking.purpose === 'training' ? $t('club.training') : $t('owner.club.time') }} · {{ $t('owner.club.min_n', { n: bill.minutes }) }}</span><b>{{ amd(bill.timeFull) }}</b>
                </div>
                <!-- the shop: one line; a tap opens the products (photo, price, − / +) -->
                <button type="button" class="bline shopln" :disabled="bill.paid" data-test="shop" @click="shopOpen = true">
                    <span
                        ><Icon name="cube" size="sm" /> {{ $t('owner.club.shop') }}<small v-if="bill.items.length" class="faint">
                            · {{ bill.items.map((i) => `${i.qty} × ${i.name}`).join(', ') }}</small
                        ></span
                    ><b>{{ amd(bill.itemsTotal) }}</b><Icon v-if="!bill.paid" name="plus" size="sm" class="plus" />
                </button>
                <div v-if="bill.timeDiscount || bill.itemsDiscount" class="bline disc">
                    <span>{{ $t('owner.club.card_discount_all', { p: bill.discountPct }) }}</span><b>−{{ amd(bill.timeDiscount + bill.itemsDiscount) }}</b>
                </div>
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
                            <small v-if="!bill.card && !booking.player">{{ $t('owner.club.find_card') }}</small>
                        </span>
                        <button v-if="!bill.card && !booking.player" type="button" class="btn sm" data-test="scan-bill" @click="scan = true"><Icon name="qr" size="sm" />{{ $t('owner.club.scan') }}</button>
                    </div>
                    <div v-if="canCard" class="split">
                        <label class="field"
                            ><span>{{ $t('owner.club.from_card') }}</span
                            ><span class="money"><input v-model.number="fromCard" class="input" type="number" min="0" :max="Math.min(bill.card!.balance, bill.total)" data-test="from-card" /><span>֏</span></span></label
                        >
                        <label class="field"><span>{{ $t('owner.club.cash') }}</span><span class="money"><input :value="cash" class="input" disabled /><span>֏</span></span></label>
                    </div>
                    <p v-if="tooMuch" class="err">{{ $t('booking.error.low_balance') }}</p>
                    <button type="button" class="btn block big" :disabled="tooMuch" data-test="take-payment" @click="pay">
                        <Icon name="check" size="sm" />
                        <template v-if="canCard && fromCard > 0 && cash > 0">{{ $t('owner.club.take_split', { a: amd(fromCard), b: amd(cash) }) }}</template>
                        <template v-else-if="canCard && fromCard > 0">{{ $t('owner.club.take_card', { a: amd(fromCard) }) }}</template>
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
.split { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
.split .field > span:first-child { display: block; font-size: 12.5px; font-weight: 800; color: var(--muted); margin-bottom: 5px; }
.big { padding: 14px; font-size: 16px; }
.paid { display: flex; gap: 8px; align-items: center; padding: 12px 14px; border-radius: 12px; background: var(--free-soft); color: var(--free); font-weight: 800; }
</style>
