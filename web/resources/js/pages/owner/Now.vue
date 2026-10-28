<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { ask } from '@/composables/useConfirm';
import { useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { trans } from '@/lib/i18n';
import { Link, router, usePoll } from '@inertiajs/vue3';
import ShopSheet from '@/components/owner/ShopSheet.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// The desk: every table now.
// - booked and due (from 30 min before): "Start game" (the time and the bill start only then) or "Didn't come" (frees the time)
// - playing: the clock and the check live (time + what they took, the total, their card), how long it may still be
//   held (+15 / +30 / +60 while the table is free), "Stop" → the check
// - free: for how long (until the next booking or the closing), the next booking, a walk-in started right away
// Every change goes live to the schedule and to the players' booking page.
interface Bill {
    minutes: number;
    running: boolean;
    time: number;
    items: { id: number; product_id: number | null; name: string; qty: number; sum: number }[];
    itemsTotal: number;
    total: number;
    card: { code: string; balance: number; cashback: number } | null;
    /** while playing: the price stays until this time, then each step adds this much */
    next: { until: string; step: number; adds: number } | null;
}
interface Court {
    id: number;
    label: string;
    size: string;
    state: 'playing' | 'to_pay' | 'booked' | 'free';
    game: {
        id: number;
        name: string;
        purpose: string;
        player: { id: number; name: string | null; phone: string | null } | null;
        startedAt: number;
        start: string;
        bookedEnd: string | null;
        holdsUntil: string;
        openEnd: boolean;
        /** minutes the table is still free after the game */
        canExtend: number;
        bill: Bill;
    } | null;
    due: { id: number; name: string; start: string; end: string; in: number; late: number; phone: string | null; canNoShow: boolean } | null;
    next: { id: number; start: string; in: number; name: string; pending: boolean } | null;
    freeMin: number | null;
    /** still held by a game until … (then free) */
    freeFrom: string | null;
    freeUntil: string | null;
}
const props = defineProps<{ courts: Court[]; products: { id: number; name: string; price: number; photo: string | null }[]; canDemo?: boolean; step: number }>();
const opts = { preserveScroll: true, preserveState: true };
// "1 h 15" / "45 min"
const len = (m: number) => (m >= 60 ? trans('owner.club.len_h', { h: Math.floor(m / 60), m: m % 60 ? ' ' + (m % 60) : '' }) : trans('owner.club.len_m', { m }));
async function startGame(c: Court) {
    if (c.due && (await ask({ title: trans('owner.club.ask_start', { name: c.due.name }), text: trans('owner.club.ask_start_text'), ok: trans('owner.club.start_game') })))
        router.post(route('owner.bookings.start', c.due.id), {}, opts);
}
async function noShow(c: Court) {
    if (c.due && (await ask({ title: trans('owner.club.ask_noshow', { name: c.due.name }), text: trans('owner.club.ask_noshow_text'), ok: trans('owner.club.no_show_btn'), danger: true })))
        router.post(route('owner.bookings.no-show', c.due.id), {}, opts);
}
async function extend(c: Court, m: number) {
    if (c.game && (await ask({ title: trans('owner.club.ask_extend', { name: c.game.name, n: m }), ok: '+' + m })))
        router.post(route('owner.bookings.extend', c.game.id), { minutes: m }, opts);
}
async function walkIn(c: Court, m: number) {
    if (await ask({ title: trans('owner.club.ask_walkin', { table: c.label, t: len(m) }), text: trans('owner.club.ask_walkin_text'), ok: trans('owner.club.start_game') }))
        router.post(route('owner.fields.walkin', c.id), { minutes: m }, opts);
}
const extendSteps = (c: Court) => [15, 30, 60].filter((m) => (c.game?.canExtend ?? 0) >= m);
const walkSteps = (c: Court) => {
    const free = c.freeMin ?? 0;
    const list = [30, 60, 90].filter((m) => free >= m);
    // less than half an hour left: what there is
    return list.length ? list : free >= props.step ? [Math.floor(free / props.step) * props.step] : [];
};
const demo = () => router.post(route('owner.now.demo'), {}, { preserveScroll: true });
const owner = useOwner();
usePoll(30000, { only: ['courts'] });

// the clock of each game, every second
const nowTs = ref(Date.now() / 1000);
let tick: number | undefined;
onMounted(() => (tick = window.setInterval(() => (nowTs.value = Date.now() / 1000), 1000)));
onBeforeUnmount(() => clearInterval(tick));
const elapsed = (from: number) => {
    const s = Math.max(0, Math.floor(nowTs.value - from));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    return `${h}:${String(m).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
};

// the shop window of one game
const shopFor = ref<number | null>(null);
const shopGame = computed(() => props.courts.find((c) => c.game?.id === shopFor.value)?.game ?? null);
// stopping ends the time on the bill: ask first
async function stop(c: Court) {
    if (!c.game) return;
    if (
        await ask({
            title: trans('owner.club.ask_stop', { name: c.game.name || c.label }),
            text: trans('owner.club.ask_stop_text', { a: amd(c.game.bill.total) }),
            ok: trans('owner.club.stop_game'),
            danger: true,
        })
    )
        router.post(route('owner.bookings.stop', c.game.id));
}
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.now')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.now') }}</h1>
            </div>
            <!-- not in production: try the desk at any hour -->
            <div v-if="canDemo && owner.role === 'owner'" class="phead-r">
                <button type="button" class="btn ghost sm" data-test="demo" @click="demo"><Icon name="plus" size="sm" />{{ $t('owner.club.demo') }}</button>
            </div>
        </div>

        <div class="courts">
            <article v-for="c in courts" :key="c.id" :class="['card', 'court', c.state]" :data-test="'court-' + c.state">
                <header>
                    <b>{{ c.label }}</b>
                    <span v-if="c.state === 'playing'" class="pill green live"><i />{{ $t('owner.club.playing') }}</span>
                    <span v-else-if="c.state === 'to_pay'" class="pill warn">{{ $t('owner.club.to_pay') }}</span>
                    <span v-else-if="c.state === 'booked'" class="pill blue">{{ $t('owner.club.state_booked') }} · {{ c.due?.start }}</span>
                    <span v-else class="pill green">{{ $t('owner.club.free_now') }}</span>
                </header>

                <!-- a game: who, the clock, the check -->
                <template v-if="c.game">
                    <div class="who">
                        <b>{{ c.game.name }}<span v-if="c.game.purpose === 'training'" class="pill" style="margin-left: 6px">{{ $t('club.training') }}</span></b>
                        <small>
                            {{ c.game.start }}–{{ c.game.bookedEnd ?? '…' }}
                            <template v-if="c.game.player"> · {{ c.game.player.name }}</template>
                            <template v-else> · {{ $t('owner.club.no_account') }}</template>
                        </small>
                    </div>
                    <div v-if="c.state === 'playing'" class="clock mono">{{ elapsed(c.game.startedAt) }}</div>

                    <div class="check">
                        <div class="ln">
                            <span>{{ $t('owner.club.time') }} · {{ $t('owner.club.min_n', { n: c.game.bill.minutes }) }}</span><b>{{ amd(c.game.bill.time) }}</b>
                        </div>
                        <!-- the shop: one line; a tap opens the products (photo, price, − / +) -->
                        <button v-if="products.length || c.game.bill.items.length" type="button" class="ln shopln" data-test="shop" @click="shopFor = c.game.id">
                            <span
                                ><Icon name="cube" size="sm" /> {{ $t('owner.club.shop') }}<small v-if="c.game.bill.items.length">
                                    · {{ c.game.bill.items.reduce((s, i) => s + i.qty, 0) }}</small
                                ></span
                            ><b>{{ amd(c.game.bill.itemsTotal) }}</b><Icon name="plus" size="sm" class="plus" />
                        </button>
                        <div class="ln total">
                            <span>{{ c.state === 'playing' ? $t('owner.club.if_stop_now') : $t('player.place.total') }}</span><b>{{ amd(c.game.bill.total) }}</b>
                        </div>
                        <p v-if="c.game.bill.next" class="nexthint">{{ $t('owner.club.price_until', { t: c.game.bill.next.until, a: amd(c.game.bill.next.adds), m: c.game.bill.next.step }) }}</p>
                        <div class="cardline">
                            <Icon name="wallet" size="sm" />
                            <span v-if="c.game.bill.card">{{ $t('owner.club.card_balance', { a: amd(c.game.bill.card.balance) }) }}</span>
                            <span v-else class="faint">{{ $t('owner.club.no_card_short') }}</span>
                        </div>
                    </div>

                    <div class="acts">
                        <button v-if="c.state === 'playing'" type="button" class="btn" data-test="stop" @click="stop(c)">
                            <Icon name="check" size="sm" />{{ $t('owner.club.stop_check') }}
                        </button>
                        <Link v-else class="btn" :href="route('owner.bookings.checkout', c.game.id)" data-test="to-pay">
                            <Icon name="wallet" size="sm" />{{ $t('owner.club.take_money', { a: amd(c.game.bill.total) }) }}
                        </Link>
                    </div>
                    <!-- more time: the table is free after the game for … (the next booking is never moved) -->
                    <div v-if="c.state === 'playing'" class="ext">
                        <small v-if="c.game.canExtend" class="muted"
                            >{{ $t('owner.club.held_until', { t: c.game.holdsUntil }) }} · {{ $t('owner.club.extend_hint', { t: len(c.game.canExtend) }) }}</small
                        >
                        <small v-else class="warnt">{{ $t('owner.club.held_until', { t: c.game.holdsUntil }) }} · {{ $t('owner.club.extend_none') }}</small>
                        <span v-if="extendSteps(c).length" class="extb">
                            <button v-for="m in extendSteps(c)" :key="m" type="button" class="chip" :data-test="`extend-${m}`" @click="extend(c, m)">+{{ m }}′</button>
                        </span>
                    </div>
                    <p v-if="c.next" class="nextl"><Icon name="clock" size="sm" />{{ $t('owner.club.next_in', { name: c.next.name, t: c.next.start, in: len(c.next.in) }) }}</p>
                </template>

                <!-- booked and due: the team is coming / here -->
                <template v-else-if="c.state === 'booked' && c.due">
                    <div class="who">
                        <b>{{ c.due.name }}</b>
                        <small
                            >{{ c.due.start }}–{{ c.due.end
                            }}<template v-if="c.due.phone">
                                · <a :href="`tel:${c.due.phone}`">{{ c.due.phone }}</a></template
                            ></small
                        >
                    </div>
                    <p :class="['duel', { late: c.due.late }]">
                        {{ c.due.late ? $t('owner.club.late_by', { n: c.due.late }) : $t('owner.club.starts_in', { t: len(c.due.in) }) }}
                    </p>
                    <div class="acts two">
                        <button type="button" class="btn" data-test="start-game" @click="startGame(c)"><Icon name="check" size="sm" />{{ $t('owner.club.start_game') }}</button>
                        <button v-if="c.due.canNoShow" type="button" class="btn ghost hotb" data-test="no-show" @click="noShow(c)">{{ $t('owner.club.no_show_btn') }}</button>
                    </div>
                    <p v-if="c.next" class="nextl"><Icon name="clock" size="sm" />{{ $t('owner.club.next_in', { name: c.next.name, t: c.next.start, in: len(c.next.in) }) }}</p>
                </template>

                <!-- free: for how long, the next booking, a walk-in right away -->
                <template v-else>
                    <div class="freebig">
                        <b>{{ c.freeMin ? len(c.freeMin) : '—' }}</b>
                        <small v-if="c.freeFrom" class="warnt">{{ $t('owner.club.free_from', { t: c.freeFrom }) }}</small>
                        <small>{{ c.next ? $t('owner.club.free_until_next', { t: c.freeUntil ?? '' }) : $t('owner.club.free_until_close', { t: c.freeUntil ?? '' }) }}</small>
                    </div>
                    <p v-if="c.next" class="nextl">
                        <Icon name="clock" size="sm" />{{ $t('owner.club.next_in', { name: c.next.name, t: c.next.start, in: len(c.next.in) })
                        }}<span v-if="c.next.pending" class="faint"> · {{ $t('owner.status.pending') }}</span>
                    </p>
                    <div v-if="walkSteps(c).length && !c.freeFrom" class="walk">
                        <small class="muted">{{ $t('owner.club.walk_in_hint') }}</small>
                        <span class="extb">
                            <button v-for="m in walkSteps(c)" :key="m" type="button" class="chip" :data-test="`walk-${m}`" @click="walkIn(c, m)">▶ {{ len(m) }}</button>
                        </span>
                    </div>
                </template>
            </article>
        </div>
        <ShopSheet
            v-if="shopGame"
            :booking-id="shopGame.id"
            :items="shopGame.bill.items"
            :products="products"
            :total="shopGame.bill.itemsTotal"
            @close="shopFor = null"
        />
    </OwnerLayout>
</template>

<style scoped>
.courts { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
.court { padding: 16px; display: flex; flex-direction: column; gap: 10px; }
.court.playing { border-color: color-mix(in srgb, var(--free) 45%, transparent); }
.court.to_pay { border-color: color-mix(in srgb, var(--warn) 50%, transparent); }
.court header { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.court header b { font-size: 16px; }
.live i { width: 7px; height: 7px; border-radius: 50%; background: currentColor; display: inline-block; margin-right: 6px; animation: blink 1.4s infinite; }
@keyframes blink { 50% { opacity: .25; } }
.who { display: flex; flex-direction: column; }
.who small { color: var(--muted); font-size: 12.5px; }
.clock { font-size: 28px; font-weight: 700; letter-spacing: .02em; }
.check { background: var(--surface-2); border-radius: 12px; padding: 8px 12px; }
.ln { display: flex; align-items: center; gap: 8px; padding: 5px 0; font-size: 14px; }
.ln span { flex: 1; }
.ln b { font-family: var(--mono); }
.ln .x { width: 24px; height: 24px; border: 0; border-radius: 6px; background: none; color: var(--muted); display: grid; place-items: center; }
.ln.disc { color: var(--accent); }
.ln.total { border-top: 1px solid var(--line-2); margin-top: 4px; padding-top: 8px; font-weight: 800; }
.ln.total b { font-size: 20px; color: var(--accent); }
.nexthint { margin: 2px 0 4px; font-size: 12px; color: var(--muted); }
.cardline { display: flex; gap: 6px; align-items: center; font-size: 12.5px; color: var(--muted); margin-top: 4px; }
.shopln { width: 100%; border: 0; background: color-mix(in srgb, var(--accent) 8%, transparent); border-radius: 10px; padding: 8px 10px; margin: 4px 0; color: var(--ink); font: inherit; text-align: left; cursor: pointer; }
.shopln small { color: var(--muted); }
.shopln .plus { color: var(--accent); }
.acts .btn { width: 100%; justify-content: center; }
.small { font-size: 13px; margin: 0; }
.court.booked { border-color: color-mix(in srgb, #5cc8ff 45%, transparent); }
.pill.blue { color: #5cc8ff; background: rgba(92, 200, 255, 0.14); }
.duel { margin: 0; font-weight: 800; font-size: 14px; color: #5cc8ff; }
.duel.late { color: var(--warn); }
.acts.two { display: grid; grid-template-columns: 1fr auto; gap: 8px; }
.hotb { color: var(--hot); border-color: rgba(255, 107, 107, 0.3); }
.ext { display: flex; flex-direction: column; gap: 6px; }
.ext small, .walk small { font-size: 12.5px; }
.warnt { color: var(--warn); }
.extb { display: flex; gap: 6px; flex-wrap: wrap; }
.extb .chip { font-weight: 800; }
.nextl { display: flex; align-items: center; gap: 6px; margin: 0; font-size: 13px; color: var(--muted); }
.freebig { display: flex; flex-direction: column; }
.freebig b { font: 700 26px var(--display); color: var(--free); }
.freebig small { color: var(--muted); font-size: 12.5px; }
.walk { display: flex; flex-direction: column; gap: 6px; padding-top: 8px; border-top: 1px solid var(--line); }
</style>
