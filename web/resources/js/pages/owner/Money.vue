<script setup lang="ts">
import Pager from '@/components/Pager.vue';
import { useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

// Money (the owner): what the place sold in the period (bookings, trainings, shop, tournaments), the cash that came in,
// what was paid from cards (that money came earlier, with the top-up), the top-ups, and the payments of the period —
// filtered by kind and by card / cash, a page at a time. Only the place's own money (ZoneSpot's fee: its own page).
interface Report {
    sales: number;
    byKind: Record<string, number>;
    cash: number;
    fromCards: number;
    topups: number;
    discounts: number;
}
interface Row { id: number; kind: string; method: string; amount: number; discount: number; who: string | null; by: string | null; note: string | null; at: string }
const props = defineProps<{
    period: 'today' | 'week' | 'month' | 'prev_month';
    /** staff the owner gave only "Today" (Settings → Menus) */
    todayOnly?: boolean;
    from: string;
    to: string;
    filters: { kind: string | null; method: string | null };
    report: Report;
    payments: { data: Row[]; page: number; pages: number; total: number };
}>();
const dm = (d: string) => `${d.slice(8)}.${d.slice(5, 7)}`;
const owner = useOwner();
// only what the place has: no cards / tournaments on a football field (no top-ups, no "from cards", no card filter)
const has = (f: string) => (owner.value.features ?? []).includes(f);
const shown = (k: string) => (k === 'topup' ? has('wallet') : k === 'tournament' ? has('tournaments') : k === 'shop' ? has('shop') : k === 'training' ? has('trainings') : true);
const KINDS = ['booking', 'training', 'shop', 'tournament', 'topup'].filter(shown);
const kinds = computed(() => Object.fromEntries(Object.entries(props.report.byKind).filter(([k, v]) => shown(k) || v)));
// a new period or filter starts the list on its first page
function go(change: Record<string, string | null>) {
    const q = { period: props.period, kind: props.filters.kind, method: props.filters.method, ...change };
    router.get(route('owner.money'), Object.fromEntries(Object.entries(q).filter(([, v]) => v)), { preserveScroll: true, replace: true });
}
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.money')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }} · {{ from === to ? dm(from) : `${dm(from)} – ${dm(to)}` }}</div>
                <h1>{{ $t('owner.nav.money') }}</h1>
            </div>
        </div>
        <div v-if="!todayOnly" class="seg mseg" role="group" style="margin-bottom: 14px; max-width: 480px">
            <button v-for="p in ['today', 'week', 'month', 'prev_month']" :key="p" type="button" :aria-pressed="period === p" @click="go({ period: p })">
                {{ $t(p === 'prev_month' ? 'owner.fee.prev_month' : 'owner.club.period_' + p) }}
            </button>
        </div>

        <div :class="['kpis', { n2: !has('wallet') }]">
            <div class="card kp"><small>{{ $t('owner.club.sales_total') }}</small><b>{{ amd(report.sales) }}</b></div>
            <div class="card kp"><small>{{ $t('owner.club.cash_in') }}</small><b>{{ amd(report.cash) }}</b></div>
            <template v-if="has('wallet')">
                <div class="card kp"><small>{{ $t('owner.club.from_cards') }}</small><b>{{ amd(report.fromCards) }}</b></div>
                <div class="card kp"><small>{{ $t('owner.club.topups') }}</small><b>{{ amd(report.topups) }}</b></div>
            </template>
        </div>
        <section class="card panel">
            <div class="sec-h"><h2>{{ $t('owner.club.by_kind') }}</h2></div>
            <button v-for="(v, k) in kinds" :key="k" type="button" class="krow" @click="go({ kind: k as string })">
                <span>{{ $t('club.kind.' + k) }}</span>
                <i :style="{ width: report.sales ? (v / report.sales) * 100 + '%' : '0' }" />
                <b class="mono">{{ amd(v) }}</b>
            </button>
            <p v-if="report.discounts" class="faint" style="font-size: 12.5px; margin: 10px 0 0">{{ $t('owner.club.discounts_given', { a: amd(report.discounts) }) }}</p>
        </section>

        <section class="card panel" style="margin-top: 14px" data-test="payments">
            <div class="sec-h"><h2>{{ $t('owner.club.payments') }}</h2></div>
            <div class="filters">
                <div class="chips" role="group" :aria-label="$t('owner.money.kind')">
                    <button type="button" :class="['chip', { on: !filters.kind }]" @click="go({ kind: null })">{{ $t('owner.money.everything') }}</button>
                    <button v-for="k in KINDS" :key="k" type="button" :class="['chip', { on: filters.kind === k }]" @click="go({ kind: k })">{{ $t('club.kind.' + k) }}</button>
                </div>
                <div v-if="has('wallet')" class="seg sm" role="group" :aria-label="$t('owner.money.method')">
                    <button type="button" :aria-pressed="!filters.method" @click="go({ method: null })">{{ $t('common.all') }}</button>
                    <button type="button" :aria-pressed="filters.method === 'card'" @click="go({ method: 'card' })">{{ $t('owner.money.by_card') }}</button>
                    <button type="button" :aria-pressed="filters.method === 'cash'" @click="go({ method: 'cash' })">{{ $t('owner.money.in_cash') }}</button>
                </div>
            </div>
            <div v-for="r in payments.data" :key="r.id" class="prow2">
                <span class="mono faint">{{ r.at }}</span>
                <span class="w"
                    ><b>{{ $t('club.kind.' + r.kind) }}</b><small>{{ [r.who, r.note, r.by ? $t('owner.club.by', { name: r.by }) : null].filter(Boolean).join(' · ') }}</small></span
                >
                <span class="mono amt">{{ amd(r.amount) }}<small class="faint"> · {{ $t('owner.club.m_' + r.method) }}</small></span>
            </div>
            <p v-if="!payments.data.length" class="faint" style="margin: 4px 0">{{ $t('owner.club.no_payments') }}</p>
            <Pager :page="payments.page" :pages="payments.pages" :total="payments.total" />
        </section>
    </OwnerLayout>
</template>

<style scoped>
.mseg button { padding-inline: 10px; font-size: 13px; }
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 14px; }
.kp { padding: 14px 16px; }
.kp small { display: block; color: var(--muted); font-weight: 700; font-size: 12.5px; }
.kp b { font-family: var(--display); font-size: 24px; }
.krow { display: grid; grid-template-columns: 140px 1fr auto; gap: 12px; align-items: center; padding: 7px 4px; width: 100%; border: 0; background: none; color: inherit; font: inherit; text-align: left; cursor: pointer; border-radius: 8px; }
.krow:hover { background: var(--surface-2); }
.krow i { height: 8px; border-radius: 4px; background: var(--accent); min-width: 2px; }
.filters { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; margin-bottom: 6px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
.chip { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--line-2); background: var(--surface-2); color: var(--muted); font: 700 12.5px var(--body); cursor: pointer; }
.chip.on { background: var(--accent); color: #0b0f0c; border-color: transparent; }
.prow2 { display: flex; gap: 12px; align-items: baseline; padding: 9px 0; border-bottom: 1px solid var(--line); font-size: 13.5px; }
.prow2 .w { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.prow2 small { color: var(--muted); font-size: 12.5px; }
.amt { white-space: nowrap; }
/* phones: the name and the amount on one line, the bar under them (long Armenian names don't run into the bar) */
@media (max-width: 560px) { .krow { grid-template-columns: minmax(0, 1fr) auto; row-gap: 4px; } .krow i { grid-column: 1 / -1; grid-row: 2; } .prow2 { flex-wrap: wrap; } .prow2 .w { order: 3; flex-basis: 100%; } .amt { margin-left: auto; } }
</style>
