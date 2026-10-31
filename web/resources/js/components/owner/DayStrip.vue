<script setup lang="ts">
import { fdate } from '@/composables/useOwner';
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

// The next 7 days to pick from on the schedule: the weekday, the date and a bar of how full the day is (green quiet,
// yellow busy, red almost full; the same levels as the Occupancy card). The numbers on top and the list below follow
// the day picked.
const props = defineProps<{ days: { date: string; count: number; full: number }[]; today: string; picked: string | null }>();
const level = (p: number) => (p >= 70 ? 'hi' : p >= 35 ? 'mid' : 'lo');
// the day lights up at once; only what changes with the day is loaded again (not the whole page)
const shown = ref(props.picked ?? props.today);
watch(
    () => props.picked,
    (p) => (shown.value = p ?? props.today),
);
function go(d: string) {
    shown.value = d;
    router.get(route('owner.schedule'), d === props.today ? {} : { date: d }, { preserveScroll: true, preserveState: true, only: ['view', 'date', 'day', 'upcoming', 'kpi'] });
}
</script>

<template>
    <div class="dstrip" role="group" :aria-label="$t('owner.dashboard.chart_week')" data-test="day-strip">
        <button
            v-for="d in days"
            :key="d.date"
            type="button"
            :class="['dd', { on: shown === d.date }]"
            :aria-pressed="shown === d.date"
            :title="$t('owner.sched.day_full', { n: d.count, p: d.full })"
            :data-test="'day-' + d.date"
            @click="go(d.date)"
        >
            <small>{{ d.date === today ? $t('common.today') : fdate(d.date, { weekday: 'short' }) }}</small>
            <b>{{ fdate(d.date, { day: 'numeric' }) }}</b>
            <i :class="['bar', level(d.full)]"><u :style="{ width: Math.max(d.full, d.count ? 6 : 0) + '%' }" /></i>
        </button>
    </div>
    <div class="dlegend" aria-hidden="true">
        <span><i class="lo" />{{ $t('owner.sched.full_lo') }}</span><span><i class="mid" />{{ $t('owner.sched.full_mid') }}</span
        ><span><i class="hi" />{{ $t('owner.sched.full_hi') }}</span>
    </div>
</template>

<style scoped>
.dstrip { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
.dd { display: flex; flex-direction: column; align-items: center; gap: 1px; padding: 8px 2px; border-radius: 14px; border: 1px solid var(--line-2); background: var(--surface); color: var(--muted); font: inherit; cursor: pointer; min-width: 0; }
.dd small { font-size: 11.5px; font-weight: 700; white-space: nowrap; }
.dd b { font: 600 18px var(--display); color: var(--ink); }
.dd .bar { display: block; width: 70%; height: 5px; border-radius: 3px; background: var(--surface-3); margin-top: 5px; overflow: hidden; }
.dd .bar u { display: block; height: 100%; border-radius: 3px; }
.bar.lo u, .dlegend .lo { background: var(--accent); }
.bar.mid u, .dlegend .mid { background: var(--warn); }
.bar.hi u, .dlegend .hi { background: var(--hot); }
.dd.on .bar { background: rgba(0, 0, 0, 0.18); }
.dd.on .bar u { background: #0b0f0c; }
.dlegend { display: flex; flex-wrap: wrap; gap: 4px 12px; margin: 6px 2px 12px; font-size: 11.5px; color: var(--faint); font-weight: 600; }
.dlegend i { display: inline-block; width: 9px; height: 9px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; }
.dd.on { background: var(--accent); border-color: transparent; color: #0b0f0c; }
.dd.on b { color: #0b0f0c; }
.dd:not(.on):hover { border-color: var(--accent); }
</style>
