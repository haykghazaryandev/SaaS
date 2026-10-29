<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import ShopSheet from '@/components/owner/ShopSheet.vue';
import { ask } from '@/composables/useConfirm';
import { useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { trans } from '@/lib/i18n';
import { Link, router, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// The desk ("Now"), one big card per table (style A):
// - a game: the minutes left counting down to its end, a bar, when it started / ends, the next booking, what they pay
//   so far; "+ Add time" (only what fits before the next booking or the closing, each with what it adds; other
//   bookings never move), "+ Item" (the shop), "Take money". When the time is up the card turns red, a sound plays
//   (if switched on) and the bill does not grow: take the money or add time.
// - a booked team due: in how many minutes (or how late), "Start game" / "Didn't come" (frees the time)
// - free: for how long, the next booking, "+ New game" with lengths that fit and their price from the price list
interface Bill {
    minutes: number;
    running: boolean;
    time: number;
    items: { id: number; product_id: number | null; name: string; qty: number; sum: number }[];
    itemsTotal: number;
    total: number;
    card: { code: string; balance: number; cashback: number } | null;
}
interface Limit {
    by: 'next' | 'close';
    at: string;
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
        endsAt: number;
        stoppedAt: string | null;
        start: string;
        bookedEnd: string | null;
        holdsUntil: string;
        openEnd: boolean;
        canExtend: number;
        addPrices: Record<string, number>;
        limit: Limit;
        bill: Bill;
    } | null;
    due: { id: number; name: string; start: string; end: string; in: number; late: number; phone: string | null; canNoShow: boolean } | null;
    next: { id: number; start: string; in: number; name: string; pending: boolean } | null;
    freeMin: number | null;
    freeFrom: string | null;
    freeUntil: string | null;
    limit: Limit;
    walkPrices: { min: number; price: number; fits: boolean }[];
}
type St = 'play' | 'train' | 'soon' | 'up' | 'topay' | 'due' | 'free';
const props = defineProps<{ courts: Court[]; products: { id: number; name: string; price: number; photo: string | null }[]; canDemo?: boolean; step: number }>();
const opts = { preserveScroll: true, preserveState: true };
const owner = useOwner();
usePoll(30000, { only: ['courts'] });

// ---------- the clock: every second ----------
const nowTs = ref(Date.now() / 1000);
let tick: number | undefined;
onMounted(() => (tick = window.setInterval(() => (nowTs.value = Date.now() / 1000), 1000)));
onBeforeUnmount(() => clearInterval(tick));
const hm = (ts: number) => new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Yerevan', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(ts * 1000));
const plusMin = (at: string, m: number) => {
    const [h, mm] = at.split(':').map(Number);
    const t = (h * 60 + mm + m) % (24 * 60);
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
};
// "1 h 15" / "45 min"
const len = (m: number) => (m >= 60 ? trans('owner.club.len_h', { h: Math.floor(m / 60), m: m % 60 ? ' ' + (m % 60) : '' }) : trans('owner.club.len_m', { m }));

function stateOf(c: Court): St {
    const g = c.game;
    if (g) {
        if (c.state === 'to_pay') return 'topay';
        if (!g.openEnd) {
            const left = g.endsAt - nowTs.value;
            if (left <= 0) return 'up';
            if (left <= 5 * 60) return 'soon';
        }
        return g.purpose === 'training' ? 'train' : 'play';
    }
    return c.state === 'booked' ? 'due' : 'free';
}
const minutesLeft = (c: Court) => Math.max(0, Math.ceil(((c.game?.endsAt ?? 0) - nowTs.value) / 60));
const minutesPlayed = (c: Court) => Math.max(0, Math.floor((nowTs.value - (c.game?.startedAt ?? 0)) / 60));
const pct = (c: Court) => {
    const g = c.game;
    if (!g || g.openEnd) return 100;
    return Math.min(100, Math.max(0, ((nowTs.value - g.startedAt) / Math.max(60, g.endsAt - g.startedAt)) * 100));
};
const freeLeft = (c: Court) => {
    const m = c.freeMin ?? 0;
    return `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}`;
};
const CHIP: Record<St, [string, string]> = {
    play: ['c-play', 'owner.now.chip_play'],
    train: ['c-train', 'club.training'],
    soon: ['c-soon', 'owner.now.chip_soon'],
    up: ['c-up', 'owner.now.chip_up'],
    topay: ['c-soon', 'owner.now.chip_topay'],
    due: ['c-due', 'owner.now.chip_due'],
    free: ['c-free', 'owner.now.chip_free'],
};
const limitText = (l: Limit) => (l.by === 'next' ? trans('owner.now.next_at', { t: l.at }) : trans('owner.now.closing_at', { t: l.at }));

// ---------- the open panel of a card: add time / new game ----------
const panel = ref<Record<number, 'add' | 'new' | null>>({});
const toggle = (c: Court, p: 'add' | 'new') => (panel.value[c.id] = panel.value[c.id] === p ? null : p);
function addTime(c: Court, m: number) {
    if (!c.game) return;
    panel.value[c.id] = null;
    router.post(route('owner.bookings.extend', c.game.id), { minutes: m }, opts);
}
async function newGame(c: Court, m: number) {
    if (await ask({ title: trans('owner.club.ask_walkin', { table: c.label, t: len(m) }), text: trans('owner.club.ask_walkin_text'), ok: trans('owner.club.start_game') })) {
        panel.value[c.id] = null;
        router.post(route('owner.fields.walkin', c.id), { minutes: m }, opts);
    }
}
async function startGame(c: Court) {
    if (c.due && (await ask({ title: trans('owner.club.ask_start', { name: c.due.name }), text: trans('owner.club.ask_start_text'), ok: trans('owner.club.start_game') })))
        router.post(route('owner.bookings.start', c.due.id), {}, opts);
}
async function noShow(c: Court) {
    if (c.due && (await ask({ title: trans('owner.club.ask_noshow', { name: c.due.name }), text: trans('owner.club.ask_noshow_text'), ok: trans('owner.club.no_show_btn'), danger: true })))
        router.post(route('owner.bookings.no-show', c.due.id), {}, opts);
}
const demo = () => router.post(route('owner.now.demo'), {}, { preserveScroll: true });

// ---------- the shop window of one game ----------
const shopFor = ref<number | null>(null);
const shopGame = computed(() => props.courts.find((c) => c.game?.id === shopFor.value)?.game ?? null);

// ---------- time is up: a sound (switched on here, per device) ----------
const sound = ref(false);
try {
    sound.value = localStorage.getItem('bb.now.sound') === '1';
} catch {
    /* private mode: off */
}
let audio: AudioContext | null = null;
function setSound(on: boolean) {
    sound.value = on;
    try {
        localStorage.setItem('bb.now.sound', on ? '1' : '0');
    } catch {
        /* not kept */
    }
    if (on) beep();
}
function beep() {
    try {
        audio ??= new AudioContext();
        void audio.resume();
        [0, 0.35, 0.7].forEach((at) => {
            const o = audio!.createOscillator();
            const g = audio!.createGain();
            o.frequency.value = 880;
            g.gain.setValueAtTime(0.0001, audio!.currentTime + at);
            g.gain.exponentialRampToValueAtTime(0.25, audio!.currentTime + at + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, audio!.currentTime + at + 0.25);
            o.connect(g).connect(audio!.destination);
            o.start(audio!.currentTime + at);
            o.stop(audio!.currentTime + at + 0.3);
        });
    } catch {
        /* no sound on this device */
    }
}
// a game whose time just ended (not the ones already over when the page opened)
const upIds = computed(() => props.courts.filter((c) => stateOf(c) === 'up').map((c) => c.game!.id));
const known = new Set<number>();
let first = true;
watch(upIds, (ids) => {
    const fresh = ids.filter((id) => !known.has(id));
    ids.forEach((id) => known.add(id));
    if (!first && fresh.length && sound.value) beep();
    first = false;
}, { immediate: true });
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.now')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }} · {{ hm(nowTs) }}</div>
                <h1>{{ $t('owner.nav.now') }}</h1>
            </div>
            <div class="phead-r">
                <button type="button" :class="['btn', 'ghost', 'sm', { on: sound }]" data-test="sound" @click="setSound(!sound)">
                    {{ sound ? '🔊 ' + $t('owner.now.sound_on') : '🔇 ' + $t('owner.now.sound_off') }}
                </button>
                <!-- not in production: try the desk at any hour -->
                <button v-if="canDemo && owner.role === 'owner'" type="button" class="btn ghost sm" data-test="demo" @click="demo"><Icon name="plus" size="sm" />{{ $t('owner.club.demo') }}</button>
            </div>
        </div>

        <div class="a-grid">
            <article v-for="c in courts" :key="c.id" :class="['a-card', stateOf(c)]" :data-test="'court-' + c.state">
                <div class="a-h">
                    <h5>{{ c.label }}</h5>
                    <span :class="['chip', CHIP[stateOf(c)][0]]">{{ stateOf(c) === 'up' ? '🔔 ' : stateOf(c) === 'train' ? '🎓 ' : '' }}{{ $t(CHIP[stateOf(c)][1]) }}</span>
                </div>

                <!-- a game -->
                <template v-if="c.game">
                    <div class="a-who">
                        {{ c.game.name }}<span v-if="c.game.purpose === 'training' && stateOf(c) !== 'train'" class="tchip sm" style="margin-left: 6px">🎓 {{ $t('club.training') }}</span>
                    </div>
                    <div class="a-big">
                        <template v-if="stateOf(c) === 'up'"><b>{{ $t('owner.now.chip_up') }}</b></template>
                        <template v-else-if="stateOf(c) === 'topay'"><b class="money">{{ amd(c.game.bill.total) }}</b><span>{{ $t('owner.now.to_pay') }}</span></template>
                        <template v-else-if="c.game.openEnd"><b>{{ minutesPlayed(c) }}</b><span>{{ $t('owner.now.min_played') }}</span></template>
                        <template v-else><b>{{ minutesLeft(c) }}</b><span>{{ $t('owner.now.min_left') }}</span></template>
                    </div>
                    <div v-if="stateOf(c) !== 'topay'" :class="['bar', stateOf(c)]"><i :style="{ width: pct(c) + '%' }" /></div>
                    <div class="a-lines">
                        <div v-if="stateOf(c) === 'up'" class="upl">
                            {{ $t('owner.now.up_line', { t: c.game.holdsUntil }) }}<span class="faint"> · {{ $t('owner.now.over', { m: Math.floor((nowTs - c.game.endsAt) / 60) }) }}</span>
                        </div>
                        <div v-else-if="stateOf(c) === 'topay'">{{ $t('owner.now.finished_at', { t: c.game.stoppedAt ?? c.game.holdsUntil }) }}</div>
                        <div v-else-if="c.game.openEnd">{{ $t('owner.now.open_time', { s: c.game.start }) }}</div>
                        <div v-else>{{ $t('owner.now.started', { s: c.game.start }) }} · {{ $t('owner.now.ends_at') }} <b>{{ c.game.holdsUntil }}</b></div>
                        <div v-if="c.next">{{ $t('owner.now.next_booking') }}: <b>{{ c.next.start }}</b> {{ c.next.name }}</div>
                        <div v-else>{{ $t('owner.now.no_next') }}</div>
                        <div v-if="stateOf(c) !== 'topay'">
                            {{ $t('owner.now.so_far') }}: <b>{{ amd(c.game.bill.total) }}</b
                            ><span v-if="c.game.bill.itemsTotal" class="faint"> ({{ $t('owner.now.with_items', { a: amd(c.game.bill.itemsTotal) }) }})</span>
                        </div>
                        <div class="cardl">
                            <Icon name="wallet" size="sm" />
                            <span v-if="c.game.bill.card">{{ $t('owner.club.card_balance', { a: amd(c.game.bill.card.balance) }) }}</span>
                            <span v-else class="faint">{{ $t('owner.club.no_card_short') }}</span>
                        </div>
                    </div>
                    <div class="btns">
                        <Link
                            :class="['btn', 'sm', { ghost: !['up', 'topay'].includes(stateOf(c)) }]"
                            :href="route('owner.bookings.checkout', c.game.id)"
                            data-test="take-money"
                            >{{ $t('owner.now.take_money') }}</Link
                        >
                        <button v-if="stateOf(c) !== 'topay' && !c.game.openEnd" type="button" class="btn ghost sm" data-test="add-time" @click="toggle(c, 'add')">+ {{ $t('owner.now.add_time') }}</button>
                        <button v-if="products.length && stateOf(c) !== 'topay'" type="button" class="btn ghost sm" data-test="add-item" @click="shopFor = c.game.id">
                            + {{ $t('owner.now.item') }}<span v-if="c.game.bill.items.length" class="cnt">{{ c.game.bill.items.reduce((s, i) => s + i.qty, 0) }}</span>
                        </button>
                    </div>
                    <!-- add time: only what fits before the next booking / the closing -->
                    <div v-if="panel[c.id] === 'add'" class="panel" data-test="add-panel">
                        <h4>{{ $t('owner.now.add_title', { t: c.label }) }}</h4>
                        <p class="why">{{ $t('owner.now.no_move') }}</p>
                        <div class="opts">
                            <button
                                v-for="m in [5, 15, 30, 60]"
                                :key="m"
                                type="button"
                                class="opt"
                                :disabled="m > c.game.canExtend"
                                :data-test="`extend-${m}`"
                                @click="addTime(c, m)"
                            >
                                <b>+{{ m }} {{ $t('owner.now.min') }}</b>
                                <small v-if="m <= c.game.canExtend">{{ $t('owner.now.until', { t: plusMin(c.game.holdsUntil, m) }) }} · +{{ amd(c.game.addPrices[m] ?? 0) }}</small>
                                <small v-else>{{ limitText(c.game.limit) }}</small>
                            </button>
                        </div>
                        <button type="button" class="btn ghost sm" style="margin-top: 8px" @click="panel[c.id] = null">{{ $t('common.close') }}</button>
                    </div>
                </template>

                <!-- a booked team due -->
                <template v-else-if="c.due">
                    <div class="a-who">{{ c.due.name }}</div>
                    <div class="a-big due">
                        <b>{{ c.due.late ? '+' + c.due.late : c.due.in }}</b><span>{{ c.due.late ? $t('owner.now.min_late') : $t('owner.now.min') }}</span>
                    </div>
                    <div class="a-lines">
                        <div>{{ $t('owner.now.booked_for', { a: c.due.start, b: c.due.end }) }}<template v-if="c.due.phone"> · <a :href="`tel:${c.due.phone}`">{{ c.due.phone }}</a></template></div>
                        <div class="duel">{{ c.due.late ? $t('owner.now.comes_late', { m: c.due.late }) : $t('owner.now.comes_in', { m: c.due.in }) }}</div>
                        <div v-if="c.next">{{ $t('owner.now.next_booking') }}: <b>{{ c.next.start }}</b> {{ c.next.name }}</div>
                    </div>
                    <div class="btns">
                        <button type="button" class="btn sm" data-test="start-game" @click="startGame(c)">{{ $t('owner.club.start_game') }}</button>
                        <button v-if="c.due.canNoShow" type="button" class="btn sm red" data-test="no-show" @click="noShow(c)">{{ $t('owner.club.no_show_btn') }}</button>
                    </div>
                </template>

                <!-- free -->
                <template v-else>
                    <div class="a-big free">
                        <b>{{ freeLeft(c) }}</b><span>{{ $t('owner.now.chip_free') }}</span>
                    </div>
                    <div class="a-lines">
                        <div v-if="c.freeFrom" class="warnt">{{ $t('owner.club.free_from', { t: c.freeFrom }) }}</div>
                        <div v-if="c.next">{{ $t('owner.now.free_until', { t: c.freeUntil ?? '' }) }}</div>
                        <div v-else>{{ $t('owner.now.free_until_close', { t: c.freeUntil ?? '' }) }}</div>
                        <div v-if="c.next">
                            {{ $t('owner.now.next_booking') }}: <b>{{ c.next.start }}</b> {{ c.next.name }}<span v-if="c.next.pending" class="faint"> · {{ $t('owner.status.pending') }}</span>
                        </div>
                    </div>
                    <div v-if="!c.freeFrom && c.walkPrices.some((w) => w.fits)" class="btns">
                        <button type="button" class="btn sm" data-test="new-game" @click="toggle(c, 'new')">+ {{ $t('owner.now.new_game') }}</button>
                    </div>
                    <!-- new game: the lengths that fit, with their price -->
                    <div v-if="panel[c.id] === 'new'" class="panel" data-test="new-panel">
                        <h4>{{ $t('owner.now.new_title', { t: c.label }) }}</h4>
                        <p class="why">{{ $t('owner.now.new_why', { t: c.freeUntil ?? '' }) }}</p>
                        <div class="opts">
                            <button v-for="w in c.walkPrices" :key="w.min" type="button" class="opt" :disabled="!w.fits" :data-test="`walk-${w.min}`" @click="newGame(c, w.min)">
                                <b>{{ len(w.min) }} · {{ amd(w.price) }}</b>
                                <small v-if="w.fits">{{ $t('owner.now.price_list') }}</small>
                                <small v-else>{{ limitText(c.limit) }}</small>
                            </button>
                        </div>
                        <button type="button" class="btn ghost sm" style="margin-top: 8px" @click="panel[c.id] = null">{{ $t('common.close') }}</button>
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
.a-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 14px; }
.a-card { background: var(--surface); border: 1px solid var(--line-2); border-radius: 22px; padding: 16px; border-top: 4px solid var(--line-2); min-width: 0; }
.a-card.play { border-top-color: var(--accent); }
.a-card.train { border-top-color: #ff7ad9; }
.a-card.soon, .a-card.topay { border-top-color: var(--warn); }
.a-card.up { border-top-color: var(--hot); background: linear-gradient(180deg, rgba(255, 107, 107, 0.1), var(--surface) 60%); animation: pulse 1.4s infinite; }
.a-card.due { border-top-color: #5cc8ff; }
.a-card.free { border-top-color: var(--free); }
@keyframes pulse { 50% { box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.25); } }
.a-h { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.a-h h5 { margin: 0; font: 600 17px var(--display); }
.a-who { margin: 10px 0 2px; font-weight: 800; font-size: 15.5px; overflow: hidden; text-overflow: ellipsis; }
.a-big { display: flex; align-items: baseline; gap: 8px; margin: 6px 0 8px; }
.a-big b { font: 700 46px var(--display); line-height: 1; }
.a-big b.money { font-size: 32px; color: var(--warn); }
.a-big span { color: var(--muted); font-weight: 700; }
.a-card.up .a-big b { color: var(--hot); font-size: 28px; }
.a-big.due b { color: #5cc8ff; }
.a-big.free b { color: var(--free); }
.a-lines { font-size: 13px; color: var(--muted); margin: 8px 0 12px; display: grid; gap: 3px; }
.a-lines b { color: var(--ink); }
.upl { color: var(--hot); font-weight: 800; }
.duel { color: #5cc8ff; font-weight: 800; }
.warnt { color: var(--warn); }
.cardl { display: flex; align-items: center; gap: 6px; }
.chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 800; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
.c-play { background: rgba(200, 255, 61, 0.14); color: var(--accent); }
.c-train { background: rgba(255, 122, 217, 0.16); color: #ff9be3; }
.c-soon { background: rgba(255, 181, 71, 0.16); color: var(--warn); }
.c-up { background: rgba(255, 107, 107, 0.18); color: var(--hot); }
.c-due { background: rgba(92, 200, 255, 0.15); color: #5cc8ff; }
.c-free { background: rgba(94, 240, 168, 0.13); color: var(--free); }
.bar { height: 8px; border-radius: 99px; background: var(--surface-3); overflow: hidden; }
.bar i { display: block; height: 100%; border-radius: 99px; background: var(--accent); transition: width 0.4s; }
.bar.soon i { background: var(--warn); }
.bar.up i { background: var(--hot); }
.bar.train i { background: #ff7ad9; }
.btns { display: flex; flex-wrap: wrap; gap: 8px; }
.btn.red { background: rgba(255, 107, 107, 0.14); color: var(--hot); border: 1px solid rgba(255, 107, 107, 0.35); }
.cnt { margin-left: 6px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 99px; background: var(--accent); color: #0b0f0c; font-size: 11px; display: inline-grid; place-items: center; }
.panel { margin-top: 12px; padding: 12px; border-radius: 14px; background: var(--surface-2); border: 1px solid var(--line-2); }
.panel h4 { margin: 0 0 4px; font-size: 14px; }
.panel .why { font-size: 12.5px; color: var(--muted); margin: 0 0 10px; }
.opts { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 6px; }
.opt { text-align: left; border: 1px solid var(--line-2); background: var(--surface); border-radius: 12px; padding: 8px 10px; color: var(--ink); font: inherit; cursor: pointer; }
.opt b { display: block; font-size: 14px; }
.opt small { display: block; font-size: 11.5px; color: var(--muted); }
.opt:not(:disabled):hover { border-color: var(--accent); }
.opt:disabled { opacity: 0.45; cursor: default; }
.opt:disabled small { color: var(--hot); }
.phead-r { display: flex; gap: 8px; flex-wrap: wrap; }
.btn.on { color: var(--accent); border-color: color-mix(in srgb, var(--accent) 45%, transparent); }
</style>
