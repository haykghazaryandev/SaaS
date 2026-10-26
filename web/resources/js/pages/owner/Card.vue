<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import QrScanner from '@/components/owner/QrScanner.vue';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

// A player's card at this place. Anyone of the staff can look at it; the money (top up, take, discount, a sale)
// opens only after scanning this card's QR: the player is at the desk with their phone.
interface Entry {
    id: number;
    amount: number;
    kind: string;
    note: string | null;
    by: string | null;
    at: string;
}
const props = defineProps<{
    card: { code: string; balance: number; discount: number; note: string | null; scanned: boolean };
    player: { id: number; name: string | null; phone: string | null; tel: string | null; avatar: string | null; no_shows: number };
    cashback: number;
    products: { id: number; name: string; price: number; photo: string | null }[];
    history: Entry[];
}>();
const opts = { preserveScroll: true, preserveState: true };
const r = (name: string, extra: Record<string, unknown> = {}) => route(name, { code: props.card.code, ...extra });
const num = (v: string | number) => Number(String(v ?? '').replace(/\D/g, '')) || 0;
const scan = ref(false);
const money = reactive({ topup: '', take: '', note: '', discount: String(props.card.discount) });
function topUp() {
    if (num(money.topup)) router.post(r('owner.cards.topup'), { amount: num(money.topup) }, { ...opts, onSuccess: () => (money.topup = '') });
}
function take() {
    if (num(money.take)) router.post(r('owner.cards.charge'), { amount: num(money.take), note: money.note || null }, { ...opts, onSuccess: () => ((money.take = ''), (money.note = '')) });
}
const saveDiscount = () => router.post(r('owner.cards.discount'), { discount: num(money.discount) }, opts);
const qty = reactive<Record<number, number>>({});
const sell = (id: number) => router.post(r('owner.cards.sell', { product: id }), { qty: qty[id] || 1 }, { ...opts, onSuccess: () => (qty[id] = 1) });
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
                    <span v-if="card.discount" class="pill">−{{ card.discount }}%</span>
                    <span v-if="card.scanned" class="pill green"><Icon name="check" size="sm" />{{ $t('owner.club.scanned') }}</span>
                </div>
            </div>
            <div class="bal">
                <small>{{ $t('owner.club.balance') }}</small><b data-test="balance">{{ amd(card.balance) }}</b>
            </div>
        </section>

        <!-- not scanned: look only; the scan opens the money for a while -->
        <section v-if="!card.scanned" class="card panel locked">
            <Icon name="qr" size="lg" />
            <div>
                <b>{{ $t('owner.club.scan_to_change') }}</b>
                <small>{{ $t('owner.club.scan_why') }}</small>
            </div>
            <button type="button" class="btn" data-test="scan-card" @click="scan = true"><Icon name="qr" size="sm" />{{ $t('owner.club.scan') }}</button>
        </section>

        <div v-else class="cgrid">
            <section class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.club.money') }}</h2></div>
                <label class="lbl">{{ $t('owner.club.topup') }}</label>
                <form class="row" style="gap: 8px" @submit.prevent="topUp">
                    <span class="money" style="flex: 1"><input v-model="money.topup" class="input" inputmode="numeric" data-test="topup" /><span>֏</span></span>
                    <button class="btn"><Icon name="plus" size="sm" />{{ $t('owner.club.topup_btn') }}</button>
                </form>
                <label class="lbl">{{ $t('owner.club.take_from_card') }}</label>
                <form class="row" style="gap: 8px" @submit.prevent="take">
                    <span class="money" style="width: 130px"><input v-model="money.take" class="input" inputmode="numeric" data-test="take" /><span>֏</span></span>
                    <input v-model="money.note" class="input" :placeholder="$t('owner.club.what_ph')" style="flex: 1" />
                    <button class="btn ghost">{{ $t('owner.club.take_btn') }}</button>
                </form>
                <p class="faint small">{{ $t('owner.club.bill_hint') }}<template v-if="cashback"> {{ $t('owner.club.cashback_note', { p: cashback }) }}</template></p>
                <label class="lbl">{{ $t('owner.club.discount') }}</label>
                <form class="row" style="gap: 8px" @submit.prevent="saveDiscount">
                    <span class="money" style="width: 120px"><input v-model="money.discount" class="input" inputmode="numeric" /><span>%</span></span>
                    <button class="btn ghost sm">{{ $t('common.save') }}</button>
                </form>
            </section>
            <section v-if="products.length" class="card panel">
                <div class="sec-h"><h2>{{ $t('owner.club.sell_from_card') }}</h2></div>
                <div v-for="p in products" :key="p.id" class="line">
                    <img v-if="p.photo" :src="p.photo" alt="" class="thumb" />
                    <span class="lk"><b>{{ p.name }}</b><small>{{ amd(p.price) }}</small></span>
                    <input v-model.number="qty[p.id]" class="input qty" type="number" min="1" max="100" placeholder="1" :aria-label="$t('owner.club.qty')" />
                    <button type="button" class="btn sm" @click="sell(p.id)">{{ $t('owner.club.from_card') }}</button>
                </div>
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
.who { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
.who h1 a { color: var(--ink); }
.bal { text-align: right; }
.bal small { display: block; color: var(--muted); font-size: 12.5px; font-weight: 700; }
.bal b { font-family: var(--display); font-size: 30px; }
.locked { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-top: 14px; }
.locked > .ico { color: var(--accent); }
.locked div { flex: 1; min-width: 200px; display: flex; flex-direction: column; }
.locked small { color: var(--muted); font-size: 13px; }
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
