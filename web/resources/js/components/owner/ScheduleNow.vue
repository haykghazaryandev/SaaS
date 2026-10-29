<script setup lang="ts">
import { fdate, openBooking, useOwner, type BookingRow } from '@/composables/useOwner';
import { hhmm } from '@/composables/useShared';
import { trans } from '@/lib/i18n';
import { computed } from 'vue';

// The schedule from now on (style A): no grid, the bookings in time order under "Playing now", "In the next hour", "Later today", "Tomorrow" and "Later this week". A row: the start (big), when
// (in 35 min / ends 18:40 / until 19:00), who, marks (request, training, weekly), the court. A tap opens the booking.
const props = defineProps<{ rows: BookingRow[]; today: string; now: number; hours: [number, number] | null }>();
const owner = useOwner();
const fields = computed(() => owner.value.fields.filter((f) => f.active));
const courtOf = (id: number) => fields.value.find((f) => f.id === id);
const courtName = (id: number) => {
    const f = courtOf(id);
    return f ? f.short || f.label : '';
};
const day = (d: string) => Math.round((Date.parse(d) - Date.parse(props.today)) / 864e5);
const sorted = computed(() => [...props.rows].sort((a, b) => a.date.localeCompare(b.date) || a.start - b.start || a.field_id - b.field_id));
const todays = computed(() => sorted.value.filter((b) => b.date === props.today));
const live = computed(() => todays.value.filter((b) => b.kind !== 'pending' && b.start <= props.now && b.end > props.now));
const hour = computed(() => todays.value.filter((b) => b.start > props.now && b.start <= props.now + 1));
const later = computed(() => todays.value.filter((b) => b.start > props.now + 1));
const tomorrow = computed(() => sorted.value.filter((b) => day(b.date) === 1));
const week = computed(() => sorted.value.filter((b) => day(b.date) >= 2));
// later this week: a small date header before each new day
const weekDays = computed(() => [...new Set(week.value.map((b) => b.date))].map((d) => ({ date: d, rows: week.value.filter((b) => b.date === d) })));
const when = (b: BookingRow) => {
    if (b.date !== props.today) return '–' + hhmm(b.end);
    if (b.start > props.now) {
        const m = Math.round((b.start - props.now) * 60);
        return m >= 60 ? trans('owner.sched.in_h', { h: Math.floor(m / 60), m: String(m % 60).padStart(2, '0') }) : trans('owner.sched.in_min', { m });
    }
    return trans('owner.sched.ends', { t: hhmm(b.end) });
};
const cls = (b: BookingRow) => (b.kind === 'pending' ? 'req' : b.purpose === 'training' ? 'train' : b.start <= props.now && b.end > props.now && b.date === props.today ? 'live' : b.weekly ? 'week' : '');
const dayTitle = (d: string) => (day(d) === 1 ? trans('common.tomorrow') : fdate(d, { weekday: 'long' })) + ', ' + fdate(d, { day: 'numeric', month: 'short' });
const sections = computed(() => [
    { key: 'now', title: trans('owner.sched.playing_now'), sub: fdate(props.today, { weekday: 'short', day: 'numeric', month: 'short' }) + ' · ' + hhmm(props.now), rows: live.value },
    { key: 'hour', title: trans('owner.sched.next_hour'), sub: `${hhmm(props.now)}–${hhmm(Math.min(24, props.now + 1))}`, rows: hour.value },
    { key: 'later', title: trans('owner.sched.later_today'), sub: props.hours ? `${hhmm(Math.min(24, props.now + 1))}–${hhmm(props.hours[1])}` : '', rows: later.value },
]);
</script>

<template>
    <div class="snow" data-test="schedule-now">
        <template v-for="s in sections" :key="s.key">
            <div class="a-sec">
                <h4>{{ s.title }}</h4>
                <small>{{ s.sub }}</small>
            </div>
            <button v-for="b in s.rows" :key="b.id" type="button" :class="['a-row', cls(b)]" :data-test="'srow-' + b.id" @click="openBooking(b.id)">
                <span class="t"
                    ><b>{{ hhmm(b.start) }}</b><small>{{ when(b) }}</small></span
                >
                <span class="w"
                    ><b>{{ b.team || b.name }}</b
                    ><small class="marks"
                        ><span v-if="cls(b) === 'live'" class="mk live">● {{ $t('owner.sched.live') }}</span
                        ><span v-if="b.kind === 'pending'" class="mk req">{{ $t('owner.sched.request') }}</span
                        ><span v-if="b.purpose === 'training'" class="mk train">🎓 {{ $t('club.training') }}</span
                        ><span v-if="b.weekly" class="mk week">↻ {{ $t('owner.schedule.weekly') }}</span></small
                    ></span
                >
                <span class="tb">{{ courtName(b.field_id) }}</span>
            </button>
            <div v-if="!s.rows.length" class="a-empty">{{ $t('owner.sched.nothing') }}</div>
        </template>

        <div class="a-sec">
            <h4>{{ $t('common.tomorrow') }}</h4>
            <small>{{ tomorrow.length ? $t('owner.sched.n_bookings', { n: tomorrow.length }) : '' }}</small>
        </div>
        <button v-for="b in tomorrow" :key="b.id" type="button" :class="['a-row', cls(b)]" @click="openBooking(b.id)">
            <span class="t"
                ><b>{{ hhmm(b.start) }}</b><small>{{ when(b) }}</small></span
            >
            <span class="w"
                ><b>{{ b.team || b.name }}</b
                ><small class="marks"
                    ><span v-if="b.kind === 'pending'" class="mk req">{{ $t('owner.sched.request') }}</span
                    ><span v-if="b.purpose === 'training'" class="mk train">🎓 {{ $t('club.training') }}</span
                    ><span v-if="b.weekly" class="mk week">↻ {{ $t('owner.schedule.weekly') }}</span></small
                ></span
            >
            <span class="tb">{{ courtName(b.field_id) }}</span>
        </button>
        <div v-if="!tomorrow.length" class="a-empty">{{ $t('owner.sched.nothing') }}</div>

        <div class="a-sec">
            <h4>{{ $t('owner.sched.later_week') }}</h4>
        </div>
        <template v-for="d in weekDays" :key="d.date">
            <div class="dayh">{{ dayTitle(d.date) }} · {{ $t('owner.sched.n_bookings', { n: d.rows.length }) }}</div>
            <button v-for="b in d.rows" :key="b.id" type="button" :class="['a-row', cls(b)]" @click="openBooking(b.id)">
                <span class="t"
                    ><b>{{ hhmm(b.start) }}</b><small>{{ when(b) }}</small></span
                >
                <span class="w"
                    ><b>{{ b.team || b.name }}</b
                    ><small class="marks"
                        ><span v-if="b.kind === 'pending'" class="mk req">{{ $t('owner.sched.request') }}</span
                        ><span v-if="b.purpose === 'training'" class="mk train">🎓 {{ $t('club.training') }}</span
                        ><span v-if="b.weekly" class="mk week">↻ {{ $t('owner.schedule.weekly') }}</span></small
                    ></span
                >
                <span class="tb">{{ courtName(b.field_id) }}</span>
            </button>
        </template>
        <div v-if="!weekDays.length" class="a-empty">{{ $t('owner.sched.nothing') }}</div>
    </div>
</template>

<style scoped>
.snow { max-width: 860px; }
.a-sec { margin: 18px 0 8px; display: flex; align-items: baseline; gap: 10px; }
.a-sec h4 { margin: 0; font: 600 17px var(--display); }
.a-sec small { color: var(--muted); font-weight: 700; font-size: 12.5px; }
.dayh { margin: 10px 0 6px; color: var(--muted); font-weight: 800; font-size: 13px; }
.a-row { width: 100%; display: grid; grid-template-columns: 84px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 10px 12px; border-radius: 14px; background: var(--surface); border: 1px solid var(--line-2); border-left: 4px solid #5cc8ff; margin-bottom: 6px; cursor: pointer; color: var(--ink); font: inherit; text-align: left; }
.a-row:hover { border-color: var(--line-2); background: var(--surface-2); }
.a-row.req { border-left-color: var(--warn); }
.a-row.train { border-left-color: #ff7ad9; }
.a-row.week { border-left-color: #b69cff; }
.a-row.live { border-left-color: var(--accent); background: linear-gradient(90deg, rgba(200, 255, 61, 0.07), var(--surface) 50%); }
.t b { display: block; font: 600 19px var(--display); }
.t small { color: var(--muted); font-size: 12px; font-weight: 700; }
.w { min-width: 0; }
.w b { display: block; font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.marks { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 2px; }
.mk { font-size: 11px; font-weight: 800; padding: 1px 7px; border-radius: 999px; }
.mk.live { background: rgba(200, 255, 61, 0.14); color: var(--accent); }
.mk.req { background: rgba(255, 181, 71, 0.16); color: var(--warn); }
.mk.train { background: rgba(255, 122, 217, 0.16); color: #ff9be3; }
.mk.week { background: rgba(182, 156, 255, 0.16); color: #c9b8ff; }
.tb { font: 700 12.5px var(--body); padding: 5px 10px; border-radius: 10px; background: var(--surface-3); white-space: nowrap; }
.a-empty { color: var(--faint); font-size: 13px; padding: 2px 2px 8px; }
@media (max-width: 560px) {
    .a-row { grid-template-columns: 68px minmax(0, 1fr); }
    .tb { grid-column: 2; justify-self: start; padding: 2px 8px; }
}
</style>
