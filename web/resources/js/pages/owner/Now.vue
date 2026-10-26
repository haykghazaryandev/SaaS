<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router, usePoll } from '@inertiajs/vue3';
import ShopSheet from '@/components/owner/ShopSheet.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// The desk: every table now. A game shows its check live (time so far + what they took,
// the total, their card); "+ Cola" adds to it; "Stop" fixes the time and opens the check to take the money.
// A free table shows its next booking. Games come from bookings (Schedule).
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
    state: 'playing' | 'to_pay' | 'free';
    game: {
        id: number;
        name: string;
        purpose: string;
        player: { id: number; name: string | null; phone: string | null } | null;
        startedAt: number;
        start: string;
        bookedEnd: string | null;
        bill: Bill;
    } | null;
    next: { start: string; name: string; pending: boolean } | null;
}
const props = defineProps<{ courts: Court[]; products: { id: number; name: string; price: number; photo: string | null }[]; canDemo?: boolean }>();
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
const stop = (bookingId: number) => router.post(route('owner.bookings.stop', bookingId));

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
                    <span v-else class="pill">{{ $t('owner.club.free_now') }}</span>
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
                        <button v-if="c.state === 'playing'" type="button" class="btn" data-test="stop" @click="stop(c.game.id)">
                            <Icon name="check" size="sm" />{{ $t('owner.club.stop_check') }}
                        </button>
                        <Link v-else class="btn" :href="route('owner.bookings.checkout', c.game.id)" data-test="to-pay">
                            <Icon name="wallet" size="sm" />{{ $t('owner.club.take_money', { a: amd(c.game.bill.total) }) }}
                        </Link>
                    </div>
                </template>

                <!-- free: its next booking -->
                <template v-else>
                    <p v-if="c.next" class="muted small">
                        {{ $t('owner.club.next_at', { t: c.next.start, name: c.next.name }) }}<span v-if="c.next.pending" class="faint"> · {{ $t('owner.status.pending') }}</span>
                    </p>
                    <p v-else class="faint small">{{ $t('owner.club.nothing_next') }}</p>
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
</style>
