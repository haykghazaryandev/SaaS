<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import CashbackLevel, { type Level } from '@/components/club/CashbackLevel.vue';
import QrScanner from '@/components/owner/QrScanner.vue';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

// A player's card at this place. Anyone of the staff can open it (scanning is the quick way). Putting money ON the
// card needs its QR scanned (the player at the desk with their phone); money comes OFF a card only on a game's check.
interface Entry {
    id: number;
    amount: number;
    kind: string;
    note: string | null;
    by: string | null;
    at: string;
}
const props = defineProps<{
    card: { code: string; balance: number; note: string | null; scanned: boolean };
    player: { id: number; name: string | null; phone: string | null; tel: string | null; avatar: string | null; no_shows: number };
    level: Level;
    /** games waiting for the money this card can pay (a phone booking without a player, or this player's own) */
    bills: { id: number; field: string; start: string; end: string; stopped: boolean; name: string | null; mine: boolean; total: number }[];
    history: Entry[];
}>();
const opts = { preserveScroll: true, preserveState: true };
const r = (name: string, extra: Record<string, unknown> = {}) => route(name, { code: props.card.code, ...extra });
const num = (v: string | number) => Number(String(v ?? '').replace(/\D/g, '')) || 0;
const scan = ref(false);
const money = reactive({ topup: '' });
function topUp() {
    if (num(money.topup)) router.post(r('owner.cards.topup'), { amount: num(money.topup) }, { ...opts, onSuccess: () => (money.topup = '') });
}
// attach this card to a bill and go on to it (the desk screen shows it at once)
const attach = (id: number) => router.post(route('owner.bookings.scan', id), { code: props.card.code, open: 1 });
const sign = (n: number) => (n > 0 ? '+' : '−') + amd(Math.abs(n));
</script>

<template>
    <OwnerLayout :title="player.name || $t('owner.nav.cards')">
        <Link class="backlink" :href="route('owner.cards')"><Icon name="back" size="sm" />{{ $t('owner.nav.cards') }}</Link>
        <section class="card panel who">
            <Avatar :id="player.id" :name="player.name" :src="player.avatar" size="lg" />
            <div style="flex: 1; min-width: 160px">
                <h1 style="margin: 0; font-size: 24px">
                    <Link :href="route('owner.players.show', player.id)">{{ player.name || '—' }}</Link>
                </h1>
                <a v-if="player.tel" class="muted" :href="`tel:${player.tel}`">{{ player.phone }}</a>
                <div class="row" style="gap: 6px; margin-top: 6px; flex-wrap: wrap">
                    <span v-if="level.pct" class="pill green">{{ $t('club.cashback.now', { p: level.pct }) }}</span>
                    <span v-if="card.scanned" class="pill green"><Icon name="check" size="sm" />{{ $t('owner.club.scanned') }}</span>
                </div>
            </div>
            <div class="bal">
                <small>{{ $t('owner.club.balance') }}</small><b data-test="balance">{{ amd(card.balance) }}</b>
            </div>
        </section>

        <!-- bills waiting for the money: attach the card to one (scanned on a phone; the desk updates by itself) -->
        <section v-if="bills.length" class="card panel bills" data-test="open-bills">
            <div class="sec-h"><h2>{{ $t('owner.club.open_bills') }}</h2></div>
            <div v-for="b in bills" :key="b.id" class="obill">
                <span class="w"
                    ><b>{{ b.field }} · {{ b.start }}–{{ b.end }}</b
                    ><small>{{ b.name || '—' }} · {{ b.stopped ? $t('owner.club.to_pay') : $t('owner.club.playing') }}</small></span
                >
                <b class="sum">{{ amd(b.total) }}</b>
                <Link v-if="b.mine" class="btn sm ghost" :href="route('owner.bookings.checkout', b.id)">{{ $t('owner.club.open_bill') }}</Link>
                <button v-else type="button" class="btn sm" :data-test="`attach-${b.id}`" @click="attach(b.id)">{{ $t('owner.club.attach_card') }}</button>
            </div>
        </section>

        <div class="cgrid">
            <!-- top up: only right after scanning this card's QR -->
            <section class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.club.topup_title') }}</h2></div>
                <form v-if="card.scanned" class="row" style="gap: 8px" @submit.prevent="topUp">
                    <span class="money" style="flex: 1"><input v-model="money.topup" class="input" inputmode="numeric" data-test="topup" /><span>֏</span></span>
                    <button class="btn"><Icon name="plus" size="sm" />{{ $t('owner.club.topup_btn') }}</button>
                </form>
                <div v-else class="locked">
                    <small>{{ $t('owner.club.scan_to_topup') }}</small>
                    <button type="button" class="btn" data-test="scan-card" @click="scan = true"><Icon name="qr" size="sm" />{{ $t('owner.club.scan') }}</button>
                </div>
                <p class="faint small">{{ $t('owner.club.bill_hint') }}<template v-if="level.pct"> {{ $t('owner.club.cashback_note', { p: level.pct }) }}</template></p>
            </section>
            <!-- cashback: moves up by itself with what the card has paid here (levels: Settings) -->
            <section class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.settings.cashback') }}</h2></div>
                <CashbackLevel :level="level" />
            </section>
        </div>

        <section class="card panel" style="margin-top: 14px">
            <div class="sec-h"><h2>{{ $t('owner.club.history') }}</h2></div>
            <div v-for="e in history" :key="e.id" class="hist">
                <span>{{ e.at }}</span><b :class="e.amount > 0 ? 'plus' : 'minus'">{{ sign(e.amount) }}</b
                ><small>{{ $t('club.entry.' + e.kind) }}{{ e.note ? ' · ' + e.note : '' }}{{ e.by ? ' · ' + e.by : '' }}</small>
            </div>
            <p v-if="!history.length" class="faint small">{{ $t('owner.club.no_history') }}</p>
        </section>
        <QrScanner v-if="scan" @close="scan = false" />
    </OwnerLayout>
</template>

<style scoped>
.bills { margin-bottom: 14px; border-color: color-mix(in srgb, var(--warn) 45%, transparent); }
.obill { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-top: 1px solid var(--line); flex-wrap: wrap; }
.obill:first-of-type { border-top: 0; }
.obill .w { flex: 1; min-width: 150px; display: flex; flex-direction: column; }
.obill small { color: var(--muted); font-size: 12.5px; }
.obill .sum { font-family: var(--mono); }
.who { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
.who h1 a { color: var(--ink); }
.bal { text-align: right; }
.bal small { display: block; color: var(--muted); font-size: 12.5px; font-weight: 700; }
.bal b { font-family: var(--display); font-size: 30px; }
.locked { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.locked small { flex: 1; min-width: 180px; color: var(--muted); font-size: 13px; }
.cgrid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; align-items: start; margin-top: 14px; }
.cgrid .panel { margin: 0; }
.lbl { display: block; font-size: 12.5px; font-weight: 800; color: var(--muted); margin: 12px 0 6px; }
.small { font-size: 12.5px; margin: 8px 0 0; }
.line { display: flex; align-items: center; gap: 8px; padding: 9px 0; border-bottom: 1px solid var(--line); }
.line .lk { flex: 1; min-width: 0; display: flex; flex-direction: column; }
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
