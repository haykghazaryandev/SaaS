<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import QrScanner from '@/components/owner/QrScanner.vue';
import { ask } from '@/composables/useConfirm';
import { amd } from '@/composables/useShared';
import { trans } from '@/lib/i18n';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// Taking the money of a game (the Take money window on Now, and the bill page): what is still to pay (all of it, or
// what was added after paying before), CASH by default; the player's card or part card, part cash when the place has
// cards. A phone-only booking finds the card by a scan or by the player's name / phone. They may pay before the end:
// the game goes on, paid.
export interface PayBill {
    time: number;
    timeDue: number;
    itemsDue: number;
    due: number;
    paidSoFar: number;
    total: number;
    card: { code: string; balance: number; cashback: number } | null;
}
const props = defineProps<{ bookingId: number; bill: PayBill; wallet: boolean; hasPlayer: boolean }>();
const emit = defineEmits<{ done: [] }>();
const opts = { preserveScroll: true, preserveState: true };

const due = computed(() => props.bill.due);
const canCard = computed(() => props.wallet && !!props.bill.card && props.bill.card.balance > 0);
type How = 'cash' | 'card' | 'both';
const how = ref<How>('cash');
const cardCovers = computed(() => canCard.value && props.bill.card!.balance >= due.value);
const fromCard = ref(0);
function pick(h: How) {
    how.value = h;
    // card + cash starts half and half (rounded to 100 ֏); either box can be changed, the other follows
    const half = Math.round(due.value / 200) * 100;
    fromCard.value = h === 'cash' ? 0 : Math.min(props.bill.card!.balance, h === 'both' ? half : due.value);
}
const setCash = (e: Event) => (fromCard.value = Math.max(0, due.value - (Number((e.target as HTMLInputElement).value) || 0)));
watch(
    () => [due.value, props.bill.card?.balance],
    () => {
        if (!canCard.value || (how.value === 'card' && !cardCovers.value)) how.value = 'cash';
        pick(how.value);
    },
    { immediate: true },
);
const cash = computed(() => Math.max(0, due.value - (fromCard.value || 0)));
const tooMuch = computed(() => how.value !== 'cash' && (fromCard.value > props.bill.card!.balance || fromCard.value > due.value));

// the card of a phone-only booking: a scan (phone / desk camera) or the player's name / phone
const scan = ref(false);
const onCode = (code: string) => router.post(route('owner.bookings.scan', props.bookingId), { code }, opts);
const find = ref('');
const found = ref<{ code: string; name: string | null; phone: string | null; balance: number }[]>([]);
let findT: number | undefined;
watch(find, (q) => {
    clearTimeout(findT);
    if (q.trim().length < 2) return (found.value = []);
    findT = window.setTimeout(async () => {
        const r = await fetch(route('owner.bookings.cards', { booking: props.bookingId, q: q.trim() }), { headers: { Accept: 'application/json' } });
        found.value = r.ok ? await r.json() : [];
    }, 250);
});
function pickCard(code: string) {
    find.value = '';
    found.value = [];
    onCode(code);
}

const busy = ref(false);
async function pay() {
    const card = how.value === 'cash' ? 0 : fromCard.value || 0;
    const money = how.value === 'cash' ? due.value : cash.value;
    const title =
        card > 0 && money > 0
            ? trans('owner.club.take_split', { a: amd(card), b: amd(money) })
            : card > 0
              ? trans('owner.club.take_card', { a: amd(card) })
              : trans('owner.club.take_cash', { a: amd(money) });
    const lines = [
        card > 0 && props.bill.card ? trans('owner.club.ask_card_after', { a: amd(props.bill.card.balance - card) }) : '',
        money > 0 ? trans('owner.club.ask_cash_hands') : '',
        props.bill.card?.cashback ? trans('owner.club.cashback_note', { p: props.bill.card.cashback }) + ' +' + amd(Math.floor((due.value * props.bill.card.cashback) / 100)) : '',
    ].filter(Boolean);
    if (!(await ask({ title: title + '?', text: lines.join('\n'), ok: trans('owner.club.ask_take') }))) return;
    busy.value = true;
    router.post(route('owner.bookings.pay', props.bookingId), { card, cash: money }, { ...opts, onSuccess: () => emit('done'), onFinish: () => (busy.value = false) });
}
</script>

<template>
    <div class="paybox">
        <div v-if="wallet" class="cardrow">
            <Icon name="wallet" />
            <span class="w">
                <b>{{ bill.card ? $t('owner.club.card_balance', { a: amd(bill.card.balance) }) : $t('owner.club.no_card_short') }}</b>
                <small v-if="!bill.card && !hasPlayer">{{ $t('owner.club.find_card_why') }}</small>
            </span>
            <button v-if="!bill.card && !hasPlayer" type="button" class="btn sm" data-test="scan-bill" @click="scan = true"><Icon name="qr" size="sm" />{{ $t('owner.club.scan') }}</button>
        </div>
        <div v-if="wallet && !bill.card && !hasPlayer" class="findcard">
            <input v-model="find" class="input" type="search" :placeholder="$t('owner.club.find_card_search')" data-test="find-card" />
            <button v-for="c in found" :key="c.code" type="button" class="frow" :data-test="`found-${c.code}`" @click="pickCard(c.code)">
                <span class="w"><b>{{ c.name || '—' }}</b><small>{{ c.phone }}</small></span><b>{{ amd(c.balance) }}</b>
            </button>
            <small v-if="find.trim().length >= 2 && !found.length" class="faint">{{ $t('owner.club.no_cards_found') }}</small>
        </div>
        <!-- how they pay: cash first (the usual way); the card choices only when there is a card with money -->
        <div v-if="canCard" class="seg how" role="group" :aria-label="$t('owner.club.payment')">
            <button type="button" :aria-pressed="how === 'cash'" data-test="how-cash" @click="pick('cash')">{{ $t('owner.club.cash') }}</button>
            <button type="button" :aria-pressed="how === 'card'" :disabled="!cardCovers" data-test="how-card" @click="pick('card')">{{ $t('owner.club.from_card') }}</button>
            <button type="button" :aria-pressed="how === 'both'" data-test="how-both" @click="pick('both')">{{ $t('owner.club.card_and_cash') }}</button>
        </div>
        <div v-if="canCard && how === 'both'" class="split">
            <label class="field"
                ><span>{{ $t('owner.club.from_card') }}</span
                ><span class="money"><input v-model.number="fromCard" class="input" type="number" min="0" :max="Math.min(bill.card!.balance, due)" data-test="from-card" /><span>֏</span></span></label
            >
            <label class="field"
                ><span>{{ $t('owner.club.cash') }}</span><span class="money"><input :value="cash" class="input" type="number" min="0" :max="due" data-test="cash" @input="setCash" /><span>֏</span></span></label
            >
        </div>
        <p v-if="bill.card?.cashback" class="faint" style="font-size: 12.5px; margin: 0 0 8px">
            {{ $t('owner.club.cashback_note', { p: bill.card.cashback }) }} +{{ amd(Math.floor((due * bill.card.cashback) / 100)) }}
        </p>
        <p v-if="tooMuch" class="err">{{ $t('booking.error.low_balance') }}</p>
        <button type="button" class="btn block big" :disabled="tooMuch || busy || due <= 0" data-test="take-payment" @click="pay">
            <Icon name="check" size="sm" />
            <template v-if="how !== 'cash' && fromCard > 0 && cash > 0">{{ $t('owner.club.take_split', { a: amd(fromCard), b: amd(cash) }) }}</template>
            <template v-else-if="how !== 'cash' && fromCard > 0">{{ $t('owner.club.take_card', { a: amd(fromCard) }) }}</template>
            <template v-else>{{ $t('owner.club.take_cash', { a: amd(due) }) }}</template>
        </button>
        <QrScanner v-if="scan" pick @code="onCode" @close="scan = false" />
    </div>
</template>

<style scoped>
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
</style>
