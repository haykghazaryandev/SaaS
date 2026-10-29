<script setup lang="ts">
import { useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { router } from '@inertiajs/vue3';

// ZoneSpot fee (the owner), layout C: the period (today / this week / last week / this month / last month), the total
// to give ZoneSpot on top (what the place took, ZoneSpot's % — shown once), then a big card for bookings, trainings and
// tournament fees: ZoneSpot's part, what the place took, and a bar of how much of the total it is. Shop and top-ups
// are not counted.
type Period = 'today' | 'week' | 'prev_week' | 'month' | 'prev_month';
const props = defineProps<{
    pct: number;
    period: Period;
    from: string;
    to: string;
    base: number;
    fee: number;
    kinds: { kind: 'booking' | 'training' | 'tournament'; base: number; fee: number }[];
}>();
const owner = useOwner();
const PERIODS: Period[] = ['today', 'week', 'prev_week', 'month', 'prev_month'];
const ICO = { booking: '📅', training: '🎓', tournament: '🏆' };
const dm = (d: string) => `${d.slice(8)}.${d.slice(5, 7)}`;
const pctText = () => (Number.isInteger(props.pct) ? String(props.pct) : props.pct.toFixed(1));
const go = (p: Period) => router.get(route('owner.fee'), { period: p }, { preserveScroll: true, replace: true });
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.fee')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }} · {{ from === to ? dm(from) : `${dm(from)} – ${dm(to)}` }}</div>
                <h1>{{ $t('owner.nav.fee') }}</h1>
            </div>
        </div>
        <div class="feewrap">
            <div class="tabs" role="group">
                <button v-for="p in PERIODS" :key="p" type="button" :class="{ on: period === p }" :data-test="'fee-' + p" @click="go(p)">{{ $t('owner.fee.p_' + p) }}</button>
            </div>
            <div class="c-total" data-test="fee">
                <div>
                    <small>{{ $t('owner.fee.to_give') }}</small><span class="big">{{ amd(fee) }}</span>
                </div>
                <div class="r">
                    {{ $t('owner.fee.taken') }}<b>{{ amd(base) }}</b>ZoneSpot {{ pctText() }}%
                </div>
            </div>
            <div class="c-grid">
                <div v-for="k in kinds" :key="k.kind" class="c-card" :data-test="'fee-' + k.kind">
                    <span class="ico">{{ ICO[k.kind] }}</span>
                    <h3>{{ $t('owner.fee.kind_' + k.kind) }}</h3>
                    <div class="kfee">{{ amd(k.fee) }}</div>
                    <div class="kbase">{{ $t('owner.fee.taken') }}: {{ amd(k.base) }}</div>
                    <div class="share"><i :style="{ width: base ? (k.base / base) * 100 + '%' : '0' }" /></div>
                </div>
            </div>
            <p class="quiet">{{ $t('owner.fee.not_counted') }}</p>
        </div>
    </OwnerLayout>
</template>

<style scoped>
.feewrap { max-width: 980px; }
.tabs { display: flex; flex-wrap: wrap; gap: 6px; }
.tabs button { padding: 8px 14px; border-radius: 999px; border: 1px solid var(--line-2); background: var(--surface-2); color: var(--muted); font: 700 13.5px var(--body); cursor: pointer; }
.tabs button.on { background: var(--accent); color: #0b0f0c; border-color: transparent; }
.c-total { margin-top: 18px; display: flex; flex-wrap: wrap; gap: 14px 24px; align-items: center; justify-content: space-between; padding: 22px 24px; border-radius: 22px; background: linear-gradient(110deg, rgba(200, 255, 61, 0.16), rgba(94, 240, 168, 0.08) 70%), var(--surface); border: 1px solid rgba(200, 255, 61, 0.35); }
.c-total small { display: block; color: var(--muted); font-weight: 700; font-size: 13.5px; }
.c-total .big { display: block; font: 700 40px var(--display); color: var(--accent); }
.c-total .r { text-align: right; color: var(--muted); font-size: 13.5px; }
.c-total .r b { display: block; color: var(--ink); font-size: 16px; }
.c-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
.c-card { padding: 18px; border-radius: 20px; background: var(--surface); border: 1px solid var(--line); }
.c-card .ico { font-size: 22px; }
.c-card h3 { margin: 8px 0 2px; font-size: 15px; }
.kfee { font: 700 26px var(--display); margin: 6px 0; }
.kbase { color: var(--muted); font-size: 13px; }
.share { height: 8px; border-radius: 4px; background: var(--surface-3); margin-top: 12px; overflow: hidden; }
.share i { display: block; height: 100%; background: var(--accent); border-radius: 4px; }
.quiet { color: var(--faint); font-size: 12.5px; margin: 14px 0 0; }
@media (max-width: 640px) {
    .c-total { padding: 18px 16px; }
    .c-total .big { font-size: 32px; }
    .c-total .r { text-align: left; }
    .c-grid { grid-template-columns: 1fr; }
    .c-card { display: grid; grid-template-columns: 34px 1fr auto; gap: 2px 10px; align-items: center; padding: 14px; }
    .c-card .ico { grid-row: 1 / 3; }
    .c-card h3 { margin: 0; }
    .kfee { grid-column: 3; grid-row: 1 / 3; font-size: 20px; margin: 0; }
    .kbase { grid-column: 2; }
    .share { grid-column: 1 / -1; margin-top: 8px; }
}
</style>
