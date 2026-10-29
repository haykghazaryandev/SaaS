<script setup lang="ts">
import { fdate, openBooking, useOwner, type BookingRow } from '@/composables/useOwner';
import { hhmm } from '@/composables/useShared';
import { computed } from 'vue';

// One day picked on the schedule: each court's free time ("Table 1: free 10:00–17:30, 19:00–23:00"), then the
// bookings in time order (start, end, who, marks, the court). A tap opens the booking. Closed that day: says so.
const props = defineProps<{ date: string; today: string; now: number; rows: BookingRow[]; hours: [number, number] | null }>();
const owner = useOwner();
const fields = computed(() => owner.value.fields.filter((f) => f.active));
const courtName = (id: number) => {
    const f = fields.value.find((x) => x.id === id);
    return f ? f.short || f.label : '';
};
const sorted = computed(() => [...props.rows].sort((a, b) => a.start - b.start || a.field_id - b.field_id));
const isToday = computed(() => props.date === props.today);
const past = computed(() => props.date < props.today);
// the free times of each court that day (from now on, today)
const free = computed(() => {
    if (!props.hours || past.value) return [];
    const [open, close] = props.hours;
    return fields.value.map((f) => {
        let from = isToday.value ? Math.max(open, props.now) : open;
        const gaps: [number, number][] = [];
        for (const b of sorted.value.filter((x) => x.field_id === f.id)) {
            if (b.end <= from) continue;
            if (b.start > from + 0.01) gaps.push([from, b.start]);
            from = Math.max(from, b.end);
        }
        if (from < close - 0.01) gaps.push([from, close]);
        return { name: f.short || f.label, gaps };
    });
});
const cls = (b: BookingRow) =>
    b.purpose === 'training' ? 'train' : isToday.value && b.start <= props.now && b.end > props.now ? 'live' : b.past ? 'done' : b.weekly ? 'week' : '';
</script>

<template>
    <div class="sday" data-test="schedule-day">
        <div class="a-sec">
            <h4>{{ isToday ? $t('common.today') : fdate(date, { weekday: 'long' }) }}, {{ fdate(date, { day: 'numeric', month: 'long' }) }}</h4>
            <small>{{ $t('owner.sched.n_bookings', { n: rows.length }) }}</small>
        </div>
        <p v-if="!hours" class="closed">{{ $t('owner.schedule.closed_day') }}</p>
        <div v-else-if="free.length" class="frees">
            <div v-for="f in free" :key="f.name" class="fr">
                <b>{{ f.name }}</b>
                <span v-if="f.gaps.length">🟩 {{ $t('owner.sched.free') }} {{ f.gaps.map(([a, z]) => `${hhmm(a)}–${hhmm(z)}`).join(', ') }}</span>
                <span v-else class="faint">{{ $t('owner.sched.full') }}</span>
            </div>
        </div>
        <button v-for="b in sorted" :key="b.id" type="button" :class="['a-row', cls(b)]" :data-test="'srow-' + b.id" @click="openBooking(b.id)">
            <span class="t"
                ><b>{{ hhmm(b.start) }}</b><small>–{{ hhmm(b.end) }}</small></span
            >
            <span class="w"
                ><b>{{ b.team || b.name }}</b
                ><small class="marks"
                    ><span v-if="cls(b) === 'live'" class="mk live">● {{ $t('owner.sched.live') }}</span
                    ><span v-if="b.purpose === 'training'" class="mk train">🎓 {{ $t('club.training') }}</span
                    ><span v-if="b.weekly" class="mk week">↻ {{ $t('owner.schedule.weekly') }}</span></small
                ></span
            >
            <span class="tb">{{ courtName(b.field_id) }}</span>
        </button>
        <div v-if="!rows.length && hours" class="a-empty">{{ $t('owner.sched.nothing') }}</div>
    </div>
</template>

<style scoped>
.sday { max-width: 860px; }
.a-sec { margin: 6px 0 10px; display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.a-sec h4 { margin: 0; font: 600 19px var(--display); }
.a-sec small { color: var(--muted); font-weight: 700; font-size: 12.5px; }
.closed { color: var(--hot); font-weight: 700; }
.frees { display: grid; gap: 4px; margin-bottom: 12px; padding: 10px 12px; border-radius: 14px; background: var(--surface); border: 1px solid var(--line); }
.fr { display: flex; gap: 10px; font-size: 13px; }
.fr b { min-width: 64px; }
.fr span { color: var(--free); font-weight: 700; }
.a-row { width: 100%; display: grid; grid-template-columns: 84px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 10px 12px; border-radius: 14px; background: var(--surface); border: 1px solid var(--line-2); border-left: 4px solid #5cc8ff; margin-bottom: 6px; cursor: pointer; color: var(--ink); font: inherit; text-align: left; }
.a-row:hover { background: var(--surface-2); }
.a-row.train { border-left-color: #ff7ad9; }
.a-row.week { border-left-color: #b69cff; }
.a-row.done { border-left-color: #6f7a70; opacity: 0.7; }
.a-row.live { border-left-color: var(--accent); background: linear-gradient(90deg, rgba(200, 255, 61, 0.07), var(--surface) 50%); }
.t b { display: block; font: 600 19px var(--display); }
.t small { color: var(--muted); font-size: 12px; font-weight: 700; }
.w { min-width: 0; }
.w b { display: block; font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.marks { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 2px; }
.mk { font-size: 11px; font-weight: 800; padding: 1px 7px; border-radius: 999px; }
.mk.live { background: rgba(200, 255, 61, 0.14); color: var(--accent); }
.mk.train { background: rgba(255, 122, 217, 0.16); color: #ff9be3; }
.mk.week { background: rgba(182, 156, 255, 0.16); color: #c9b8ff; }
.tb { font: 700 12.5px var(--body); padding: 5px 10px; border-radius: 10px; background: var(--surface-3); white-space: nowrap; }
.a-empty { color: var(--faint); font-size: 13px; padding: 2px 2px 8px; }
@media (max-width: 560px) {
    .a-row { grid-template-columns: 68px minmax(0, 1fr); }
    .tb { grid-column: 2; justify-self: start; padding: 2px 8px; }
    .fr { flex-direction: column; gap: 0; }
}
</style>
