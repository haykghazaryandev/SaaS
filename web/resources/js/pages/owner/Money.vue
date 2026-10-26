<script setup lang="ts">
import { useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { router } from '@inertiajs/vue3';

// Money: what the place sold (bookings, trainings, shop, tournaments), the cash that came in, what was paid
// from cards (that money came earlier, with the top-up), and every payment with who took it.
interface Report {
    sales: number;
    byKind: Record<string, number>;
    cash: number;
    fromCards: number;
    topups: number;
    discounts: number;
    days: { date: string; sales: number; cash: number }[];
    rows: { id: number; kind: string; method: string; amount: number; discount: number; who: string | null; by: string | null; note: string | null; at: string }[];
}
defineProps<{ period: 'today' | 'week' | 'month'; from: string; report: Report }>();
const owner = useOwner();
const go = (p: string) => router.get(route('owner.money'), { period: p }, { preserveScroll: true, replace: true });
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.money')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.money') }}</h1>
            </div>
        </div>
        <div class="seg" role="group" style="margin-bottom: 14px; max-width: 420px">
            <button v-for="p in ['today', 'week', 'month']" :key="p" type="button" :aria-pressed="period === p" @click="go(p)">{{ $t('owner.club.period_' + p) }}</button>
        </div>
        <div class="kpis">
            <div class="card kp"><small>{{ $t('owner.club.sales_total') }}</small><b>{{ amd(report.sales) }}</b></div>
            <div class="card kp"><small>{{ $t('owner.club.cash_in') }}</small><b>{{ amd(report.cash) }}</b></div>
            <div class="card kp"><small>{{ $t('owner.club.from_cards') }}</small><b>{{ amd(report.fromCards) }}</b></div>
            <div class="card kp"><small>{{ $t('owner.club.topups') }}</small><b>{{ amd(report.topups) }}</b></div>
        </div>
        <section class="card panel">
            <div class="sec-h"><h2>{{ $t('owner.club.by_kind') }}</h2></div>
            <div v-for="(v, k) in report.byKind" :key="k" class="krow">
                <span>{{ $t('club.kind.' + k) }}</span>
                <i :style="{ width: report.sales ? (v / report.sales) * 100 + '%' : '0' }" />
                <b class="mono">{{ amd(v) }}</b>
            </div>
            <p v-if="report.discounts" class="faint" style="font-size: 12.5px; margin: 10px 0 0">{{ $t('owner.club.discounts_given', { a: amd(report.discounts) }) }}</p>
        </section>
        <section v-if="period !== 'today' && report.days.length" class="card panel">
            <div class="sec-h"><h2>{{ $t('owner.club.by_day') }}</h2></div>
            <div v-for="d in report.days" :key="d.date" class="prow2">
                <span class="mono">{{ d.date.slice(8) }}.{{ d.date.slice(5, 7) }}</span><span>{{ $t('owner.club.sales_total') }} <b>{{ amd(d.sales) }}</b></span
                ><span class="faint">{{ $t('owner.club.cash_in') }} {{ amd(d.cash) }}</span>
            </div>
        </section>
        <section class="card panel">
            <div class="sec-h"><h2>{{ $t('owner.club.payments') }}</h2></div>
            <div v-for="r in report.rows" :key="r.id" class="prow2">
                <span class="mono faint">{{ r.at }}</span>
                <span class="w"
                    ><b>{{ $t('club.kind.' + r.kind) }}</b><small>{{ [r.who, r.note, r.by ? $t('owner.club.by', { name: r.by }) : null].filter(Boolean).join(' · ') }}</small></span
                >
                <span class="mono">{{ amd(r.amount) }}<small class="faint"> · {{ $t('owner.club.m_' + r.method) }}</small></span>
            </div>
            <p v-if="!report.rows.length" class="faint" style="margin: 4px 0">{{ $t('owner.club.no_payments') }}</p>
        </section>
    </OwnerLayout>
</template>

<style scoped>
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 14px; }
.kp { padding: 14px 16px; }
.kp small { display: block; color: var(--muted); font-weight: 700; font-size: 12.5px; }
.kp b { font-family: var(--display); font-size: 24px; }
.krow { display: grid; grid-template-columns: 140px 1fr auto; gap: 12px; align-items: center; padding: 7px 0; }
.krow i { height: 8px; border-radius: 4px; background: var(--accent); min-width: 2px; }
.prow2 { display: flex; gap: 12px; align-items: baseline; padding: 8px 0; border-bottom: 1px solid var(--line); font-size: 13.5px; }
.prow2 .w { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.prow2 small { color: var(--muted); font-size: 12.5px; }
</style>
