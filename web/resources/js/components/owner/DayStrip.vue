<script setup lang="ts">
import { fdate } from '@/composables/useOwner';
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

// The next 7 days to pick from on the schedule: the weekday, the date and how many bookings. Today: from now on.
// The numbers on top and the list below follow the day picked.
const props = defineProps<{ days: { date: string; count: number }[]; today: string; picked: string | null }>();
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
            :data-test="'day-' + d.date"
            @click="go(d.date)"
        >
            <small>{{ d.date === today ? $t('common.today') : fdate(d.date, { weekday: 'short' }) }}</small>
            <b>{{ fdate(d.date, { day: 'numeric' }) }}</b>
            <i>{{ d.count }}</i>
        </button>
    </div>
</template>

<style scoped>
.dstrip { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; margin-bottom: 12px; }
.dd { display: flex; flex-direction: column; align-items: center; gap: 1px; padding: 8px 2px; border-radius: 14px; border: 1px solid var(--line-2); background: var(--surface); color: var(--muted); font: inherit; cursor: pointer; min-width: 0; }
.dd small { font-size: 11.5px; font-weight: 700; white-space: nowrap; }
.dd b { font: 600 18px var(--display); color: var(--ink); }
.dd i { font-style: normal; font-size: 11px; font-weight: 800; color: var(--faint); }
.dd.on { background: var(--accent); border-color: transparent; color: #0b0f0c; }
.dd.on b, .dd.on i { color: #0b0f0c; }
.dd:not(.on):hover { border-color: var(--accent); }
</style>
