<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import ScheduleDay from '@/components/owner/ScheduleDay.vue';
import ScheduleNow from '@/components/owner/ScheduleNow.vue';
import TodayCards, { type TodayNumbers } from '@/components/owner/TodayCards.vue';
import DayStrip from '@/components/owner/DayStrip.vue';
import { openForm, useMay, useOwner, type BookingRow } from '@/composables/useOwner';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { router, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Schedule, easy to read (it is also the place's home: Today's numbers are on top): today's bookings, requests
// waiting, trainings, how full; then the next 7 days (how full, how many bookings; tap one) and a calendar for any other
// day. Below: from now on (default: playing now, the next hour, later today, tomorrow, later this week) or the day
// picked (its bookings and each court's free time). Only accepted bookings: requests are on Requests.
const props = defineProps<{
    view: 'now' | 'day';
    date: string;
    today: string;
    nowHour: number;
    days: { date: string; count: number }[];
    kpi: TodayNumbers;
    upcoming?: { rows: BookingRow[]; hours: [number, number] | null };
    day?: { rows: BookingRow[]; hours: [number, number] | null };
}>();
const owner = useOwner();
// staff only look here (they book on Now) unless the owner let them change the schedule (Settings → Menus)
const may = useMay();
const staff = computed(() => !may('schedule', 'edit'));
usePoll(30000, { only: ['upcoming', 'day', 'days', 'kpi', 'nowHour'] });

// the time now, moving on while the page is open
const loadedAt = Date.now();
const now = ref(props.nowHour);
let tick: number | undefined;
onMounted(() => (tick = window.setInterval(() => (now.value = props.nowHour + (Date.now() - loadedAt) / 36e5), 60000)));
onBeforeUnmount(() => clearInterval(tick));
watch(
    () => props.nowHour,
    (v) => (now.value = v),
);

// any other day: the native date picker of a hidden input
const picker = ref<HTMLInputElement>();
function openPicker() {
    const el = picker.value;
    if (!el) return;
    try {
        el.showPicker();
    } catch {
        el.focus();
        el.click();
    }
}
const pick = (d: string) =>
    router.get(route('owner.schedule'), d === props.today ? {} : { date: d }, { preserveScroll: true, preserveState: true, only: ['view', 'date', 'day', 'upcoming', 'kpi'] });
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.schedule')" :new-booking="staff ? undefined : { date: view === 'day' && date >= today ? date : today }">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.schedule') }}</h1>
            </div>
            <div class="phead-r">
                <span class="calbtn">
                    <button type="button" class="btn ghost sm" data-test="calendar" @click="openPicker"><Icon name="calendar" size="sm" />{{ $t('owner.sched.other_day') }}</button>
                    <input
                        ref="picker"
                        type="date"
                        tabindex="-1"
                        aria-hidden="true"
                        :value="date"
                        @change="(e) => (e.target as HTMLInputElement).value && pick((e.target as HTMLInputElement).value)"
                    />
                </span>
                <button v-if="!staff" type="button" class="btn sm" data-test="new" @click="openForm({ date: view === 'day' && date >= today ? date : today })">
                    <Icon name="plus" size="sm" />{{ $t('common.new_booking') }}
                </button>
            </div>
        </div>

        <DayStrip :days="days" :today="today" :picked="view === 'day' ? date : null" />
        <TodayCards :kpi="kpi" :today="today" />

        <ScheduleNow v-if="view === 'now' && upcoming" :rows="upcoming.rows" :today="today" :now="now" :hours="upcoming.hours" />
        <ScheduleDay v-else-if="day" :date="date" :today="today" :now="now" :rows="day.rows" :hours="day.hours" />
    </OwnerLayout>
</template>

<style scoped>
.phead-r { display: flex; gap: 8px; flex-wrap: wrap; }
.calbtn { position: relative; }
.calbtn input { position: absolute; inset: 0; opacity: 0; pointer-events: none; }
</style>
