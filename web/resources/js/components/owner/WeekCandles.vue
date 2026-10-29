<script setup lang="ts">
import { fdate, useOwner } from '@/composables/useOwner';
import { trans } from '@/lib/i18n';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

// The next 7 days on top of the schedule: one candle a day (how full: the courts together, the % above it, its colour
// by how full) and how many bookings; a tap shows that day (today: from now on). The picked day is lit.
const props = defineProps<{ days: { date: string; occupancy: Record<number, number>; count: number }[]; today: string; picked: string | null }>();
const owner = useOwner();
const avg = (m: Record<number, number>) => {
    const v = owner.value.fields.filter((f) => f.active).map((f) => m[f.id] ?? 0);
    return v.length ? Math.round(v.reduce((a, b) => a + b, 0) / v.length) : 0;
};
const level = (p: number) => (p >= 70 ? 'hi' : p >= 35 ? 'mid' : 'lo');
const cols = computed(() =>
    props.days.map((d) => ({
        date: d.date,
        top: d.date === props.today ? trans('common.today') : fdate(d.date, { weekday: 'short' }),
        day: fdate(d.date, { day: 'numeric' }),
        today: d.date === props.today,
        p: avg(d.occupancy),
        count: d.count,
        on: (props.picked ?? props.today) === d.date,
        title: owner.value.fields.map((f) => `${f.label}: ${d.occupancy[f.id] ?? 0}%`).join(' · '),
    })),
);
</script>

<template>
    <section class="card panel week7 picker" data-test="week-candles">
        <div class="wchart" role="group" :aria-label="$t('owner.dashboard.chart_week')">
            <button
                v-for="d in cols"
                :key="d.date"
                type="button"
                :class="['wcol', { today: d.today, on: d.on }]"
                :title="d.title"
                :aria-pressed="d.on"
                :data-test="'day-' + d.date"
                @click="router.get(route('owner.schedule'), d.today ? {} : { date: d.date }, { preserveScroll: true })"
            >
                <span class="candle">
                    <i v-if="d.p" :class="level(d.p)" :style="{ height: Math.max(4, d.p) + '%' }" /><em :style="{ bottom: Math.max(4, d.p) + '%' }">{{ d.p }}%</em>
                </span>
                <small>{{ d.top }}</small>
                <b>{{ d.day }}</b>
                <small class="cnt" :title="$t('owner.sched.n_bookings', { n: d.count })">{{ d.count }}</small>
            </button>
        </div>
    </section>
</template>

<style scoped>
.picker { margin-top: 0; margin-bottom: 14px; padding: 10px; }
.picker .wcol .candle { height: 70px; margin: 18px 0 6px; width: min(26px, 60%); }
.picker .wcol.on { background: var(--surface-2); box-shadow: inset 0 0 0 2px var(--accent); }
.cnt { font-size: 10.5px !important; color: var(--faint); white-space: nowrap; }
</style>
