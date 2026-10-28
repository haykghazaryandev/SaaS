<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import HourGrid, { type Cell, type Col, type GridClosure } from '@/components/owner/HourGrid.vue';
import {
    accept,
    askDecline,
    daysBetween,
    decisions,
    dotOf,
    fdate,
    longDate,
    openBooking,
    openForm,
    pillOf,
    relDay,
    useOwner,
    whoOf,
    GRID_COLORS,
    type BookingRow,
    type Kind,
} from '@/composables/useOwner';
import { amd, hhmm } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { router, usePoll } from '@inertiajs/vue3';
import { trans } from '@/lib/i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Schedule: day (an hour grid: every table / field a column, bookings as blocks, free times as green "+ Free"),
// week (the same grid: 7 days × courts; phones: one court at a time) and list (search + status filter).
/** a column of the schedule: one football field, or all the tables of a place together ("Tables · 3") */
interface Lane {
    id: number;
    size: string;
    sport: string;
    count: number;
    label: string;
    short: string;
    courts: number[];
}
const props = defineProps<{
    view: 'day' | 'week' | 'list';
    date: string;
    today: string;
    nowHour: number;
    strip: string[];
    pendingDays: string[];
    day?: {
        open: number;
        close: number;
        closed: boolean;
        hours: [number, number] | null;
        bookings: BookingRow[];
        closures: GridClosure[];
        lanes: Lane[];
        step: number;
        /** per lane: [from, to, how many courts are taken] through the day */
        load: Record<number, [number, number, number, number[]][]>;
    };
    week?: {
        days: {
            date: string;
            hours: [number, number] | null;
            fields: Record<number, { count: number; pending: number; free: [number, number][] }>;
        }[];
        lanes: Lane[];
        bookings: BookingRow[];
        closures: GridClosure[];
        step: number;
    };
    list?: { from: string; to: string; rows: BookingRow[] };
}>();
const owner = useOwner();
// courts in use (a table switched off in Settings disappears from the schedule)
const fields = computed(() => owner.value.fields.filter((f) => f.active));

usePoll(30000, { only: ['day', 'week', 'list', 'pendingDays', 'owner', 'nowHour'] });

const read = (k: string, d: string) => {
    try {
        return localStorage.getItem(k) ?? d;
    } catch {
        return d;
    }
};
const showOff = ref(read('bb.owner.showOff', '0') === '1');
watch(showOff, (v) => {
    try {
        localStorage.setItem('bb.owner.showOff', v ? '1' : '0');
    } catch {
        /* private mode */
    }
});

// below 768px one full-width lane with a 5×5 | 11×11 toggle
const narrow = ref(false);
let mq: MediaQueryList | undefined;
const onMq = () => (narrow.value = !!mq?.matches);
onMounted(() => {
    mq = window.matchMedia('(max-width: 767px)');
    onMq();
    mq.addEventListener('change', onMq);
});
onBeforeUnmount(() => mq?.removeEventListener('change', onMq));

function go(params: { date?: string; view?: string }) {
    router.get(
        route('owner.schedule'),
        { date: params.date ?? props.date, view: params.view ?? props.view },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

// current time, moving on while the page is open
const loadedAt = Date.now();
const now = ref(props.nowHour);
let tick: number | undefined;
onMounted(() => (tick = window.setInterval(() => (now.value = props.nowHour + (Date.now() - loadedAt) / 36e5), 60000)));
onBeforeUnmount(() => clearInterval(tick));
watch(
    () => props.nowHour,
    (v) => (now.value = v),
);
const isToday = computed(() => props.date === props.today);

// ---------- date strip ----------
const chips = ref<HTMLElement>();
function centerChip() {
    const strip = chips.value;
    const cur = strip?.querySelector<HTMLElement>('[aria-pressed="true"]');
    if (strip && cur) strip.scrollLeft = cur.offsetLeft - strip.offsetLeft - strip.clientWidth / 2 + cur.offsetWidth / 2;
}
onMounted(centerChip);
watch(
    () => props.date,
    () => nextTick(centerChip),
);

// "Now": today's day view, scrolled so the now line is in sight
const wantNow = ref(false);
function goNow() {
    if (props.view !== 'day' || !isToday.value) {
        wantNow.value = true;
        go({ date: props.today, view: 'day' });
        return;
    }
    scrollNow();
}
function scrollNow() {
    grid.value?.scrollToNow();
}
watch(
    () => props.day,
    () => {
        if (wantNow.value && isToday.value) {
            wantNow.value = false;
            nextTick(scrollNow);
        }
    },
);
// one calendar button: the native date picker of a hidden input
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
// ---------- list view ----------
const qs = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
const search = ref('');
const status = ref<string>(['pending', 'accepted', 'cancelled', 'all'].includes(qs.get('status') || '') ? (qs.get('status') as string) : 'all');
const FILTERS: Record<string, (b: BookingRow) => boolean> = {
    pending: (b) => b.kind === 'pending',
    accepted: (b) => b.status === 'accepted' || b.status === 'completed',
    cancelled: (b) => b.kind === 'expired' || ['declined', 'cancelled', 'expired', 'no_show'].includes(b.status),
    all: () => true,
};
const listAll = computed(() => props.list?.rows ?? []);
const listShown = computed(() => {
    const s = search.value.toLowerCase().replace(/\s+/g, '');
    return listAll.value
        .filter(FILTERS[status.value])
        .filter((b) => !s || [b.name, b.phone, b.tel, b.team, b.code].join(' ').toLowerCase().replace(/\s+/g, '').includes(s));
});
function setStatus(s: string) {
    status.value = s;
    const u = new URL(window.location.href);
    u.searchParams.set('status', s);
    window.history.replaceState(window.history.state, '', u.toString());
}

// answered on this device but still inside the Undo window
const kindOf = (b: BookingRow): Kind => (decisions.hidden[b.id] === 'accepted' ? 'accepted' : decisions.hidden[b.id] === 'declined' ? 'off' : b.kind);
// expired, declined and cancelled don't hold the time (players can book it): hidden unless "Show…" is on
const isOff = (b: BookingRow) => ['off', 'expired'].includes(kindOf(b));

// ---------- the hour grid (day and week) ----------
const grid = ref<InstanceType<typeof HourGrid>>();
/** a booking's colour on the grid */
function stateOf(b: BookingRow): Cell {
    const k = kindOf(b);
    // didn't come, declined, expired: the time is free again
    if (k === 'off' || k === 'expired' || k === 'noshow') return 'off';
    if (k === 'pending') return 'req';
    if (b.playing) return 'live';
    if (b.past) return 'done';
    return b.weekly ? 'reg' : 'booked';
}
const col = (k: keyof typeof GRID_COLORS) => owner.value.colors?.[k] || GRID_COLORS[k];
const nameOf = (b: BookingRow) => b.team || b.name || '—';
const shownRows = (rows: BookingRow[]) => rows.filter((b) => showOff.value || (!isOff(b) && kindOf(b) !== 'noshow'));
const courtName = (f: { short?: string | null; label: string }) => f.short || f.label;
// under each court today: what is on it now
function nowSub(id: number): { sub: string; subCls: string } {
    const rows = (props.day?.bookings ?? []).filter((b) => b.field_id === id && b.date === props.today && !isOff(b));
    const on = rows.find((b) => b.start <= now.value && b.end > now.value && kindOf(b) !== 'noshow');
    if (on) return on.playing ? { sub: `● ${hhmm(on.end)}`, subCls: 'live' } : { sub: `${trans('owner.club.state_booked')} → ${hhmm(on.end)}`, subCls: 'booked' };
    const shut = (props.day?.closures ?? []).find((c) => (c.field_id === null || c.field_id === id) && c.start <= now.value && c.end > now.value);
    if (shut) return { sub: `${trans('common.closed')} → ${hhmm(shut.end)}`, subCls: 'closed' };
    const next = rows.filter((b) => b.start > now.value).sort((a, b) => a.start - b.start)[0];
    return { sub: next ? `${trans('owner.schedule.free_row')} → ${hhmm(next.start)}` : trans('owner.schedule.free_row'), subCls: 'free' };
}
const dayCols = computed<Col[]>(() =>
    fields.value.map((f) => ({ key: 'd' + f.id + props.date, date: props.date, field: f.id, head: courtName(f), ...(isToday.value ? nowSub(f.id) : {}) })),
);
const dayHours = computed(() => ({ [props.date]: props.day?.hours ?? null }) as Record<string, [number, number] | null>);
// week: on a computer every day split into its courts; on a phone one court at a time (7 columns)
const weekCourt = ref(Number(read('bb.owner.weekCourt', '0')) || 0);
watch(weekCourt, (v) => {
    try {
        localStorage.setItem('bb.owner.weekCourt', String(v));
    } catch {
        /* private mode */
    }
});
const weekField = computed(() => fields.value.find((f) => f.id === weekCourt.value) ?? fields.value[0]);
const dayName = (d: string) => (d === props.today ? trans('common.today') : fdate(d, { weekday: 'short' })) + ' ' + fdate(d, { day: 'numeric' });
const weekCols = computed<Col[]>(() => {
    const days = props.week?.days ?? [];
    if (narrow.value) return days.map((d) => ({ key: 'w' + d.date, date: d.date, field: weekField.value?.id ?? 0, head: dayName(d.date) }));
    // narrow columns: "#2" for "Table 2", else the short name
    const short = (f: { short?: string | null; label: string }) => (/(\d+)\s*$/.exec(courtName(f)) ? '#' + /(\d+)\s*$/.exec(courtName(f))![1] : courtName(f));
    return days.flatMap((d) => fields.value.map((f) => ({ key: 'w' + d.date + f.id, date: d.date, field: f.id, head: short(f), group: dayName(d.date) })));
});
const weekHours = computed(() => Object.fromEntries((props.week?.days ?? []).map((d) => [d.date, d.hours])) as Record<string, [number, number] | null>);
const weekRange = computed<[number, number]>(() => {
    // the opening hours of the week, and every booking (a game may go on after closing)
    const hs = (props.week?.days ?? []).map((d) => d.hours).filter((h): h is [number, number] => !!h);
    const bs = props.week?.bookings ?? [];
    const lo = Math.min(...hs.map((h) => h[0]), ...bs.map((b) => b.start), hs.length || bs.length ? 24 : 8);
    const hi = Math.max(...hs.map((h) => h[1]), ...bs.map((b) => b.end), hs.length || bs.length ? 0 : 24);
    return [Math.floor(lo), Math.min(24, Math.ceil(hi))];
});
const newFree = (field: number, date: string, start: number) => openForm({ field_id: field, date, start, any: false });

const fieldOf = (id: number) => fields.value.find((f) => f.id === id);
const rowPill = (b: BookingRow) => pillOf({ kind: kindOf(b), status: decisions.hidden[b.id] ?? b.status });
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.schedule')" :new-booking="{ date: daysBetween(today, date) >= 0 ? date : today }">
        <div class="phead">
            <div>
                <div class="kicker">{{ $t('common.bookings') }} · {{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.schedule') }}</h1>
            </div>
            <div class="phead-r">
                <button type="button" class="btn new" data-test="new" @click="openForm({ date: daysBetween(today, date) >= 0 ? date : today })">
                    <Icon name="plus" size="sm" />{{ $t('common.new_booking') }}
                </button>
            </div>
        </div>
        <div class="toolbar">
            <div class="seg" role="group">
                <button
                    v-for="v in [
                        ['day', 'calendar'],
                        ['week', 'grid'],
                        ['list', 'filter'],
                    ]"
                    :key="v[0]"
                    type="button"
                    :aria-pressed="view === v[0]"
                    @click="go({ view: v[0] })"
                >
                    <Icon :name="v[1]" size="sm" />{{ $t(`owner.schedule.${v[0]}`) }}
                </button>
            </div>
            <span class="grow" />
            <label v-if="view === 'day'" class="switch"><input v-model="showOff" type="checkbox" />{{ $t('owner.schedule.show_inactive') }}</label>
        </div>

        <!-- date bar: day strip, one calendar button, "Now" -->
        <div v-if="view !== 'list'" class="datebar">
            <div ref="chips" class="chips fade">
                <button v-for="d in strip" :key="d" type="button" class="chip dchip" :aria-pressed="d === date" @click="go({ date: d })">
                    <small>{{ d === today ? $t('common.today') : fdate(d, { weekday: 'short' }) }}</small
                    ><b>{{ fdate(d, { day: 'numeric' }) }}</b
                    ><i v-if="pendingDays.includes(d)" class="dp" />
                </button>
            </div>
            <span class="calbtn">
                <button
                    type="button"
                    class="x"
                    :title="$t('owner.schedule.calendar')"
                    :aria-label="$t('owner.schedule.calendar')"
                    @click="openPicker"
                >
                    <Icon name="calendar" size="sm" />
                </button>
                <input
                    ref="picker"
                    type="date"
                    tabindex="-1"
                    aria-hidden="true"
                    :value="date"
                    @change="(e) => (e.target as HTMLInputElement).value && go({ date: (e.target as HTMLInputElement).value })"
                />
            </span>
            <button type="button" class="btn ghost sm nowbtn" data-test="now" @click="goNow">
                <Icon name="clock" size="sm" />{{ $t('common.now') }}
            </button>
        </div>

        <!-- day: the hour grid, every table / field a column -->
        <template v-if="view === 'day' && day">
            <h2 style="font-size: 18px; margin: 4px 0 12px">
                {{ relDay(date, today) }}<template v-if="Math.abs(daysBetween(today, date)) <= 1"> · {{ longDate(date) }}</template>
            </h2>
            <div v-if="day.closed" class="closed-note"><Icon name="clock" size="sm" />{{ $t('owner.schedule.closed_day') }}</div>
            <div class="hgwrap" data-test="day-grid">
                <HourGrid
                    ref="grid"
                    :cols="dayCols"
                    :bookings="shownRows(day.bookings)"
                    :closures="day.closures"
                    :hours="dayHours"
                    :from="day.open"
                    :to="day.close"
                    :now="now"
                    :today="today"
                    :step="day.step"
                    :pxh="narrow ? 56 : 64"
                    :compact="narrow && dayCols.length > 4"
                    :state-of="stateOf"
                    :label="nameOf"
                    :colors="owner.colors"
                    @open="openBooking"
                    @free="newFree"
                />
            </div>
            <!-- the colours: Settings → Schedule colours -->
            <div class="hglegend">
                <span><i class="lf" :style="{ borderColor: col('free') }" />{{ $t('owner.schedule.free_row') }}</span
                ><span><i :style="{ background: col('booked') }" />{{ $t('owner.schedule.legend_booked') }}</span
                ><span><i :style="{ background: col('live') }" />{{ $t('owner.schedule.playing') }}</span
                ><span><i :style="{ background: col('req') }" />{{ $t('owner.schedule.request') }}</span
                ><span><i :style="{ background: col('reg') }" />{{ $t('owner.schedule.weekly') }}</span
                ><span><i :style="{ background: col('done') }" />{{ $t('owner.schedule.legend_done') }}</span>
            </div>
        </template>

        <!-- week: the same grid, 7 days (phones: one court at a time) -->
        <template v-else-if="view === 'week' && week">
            <div v-if="narrow && fields.length > 1" class="seg lanesw" role="group" :aria-label="$t('common.field')">
                <button v-for="f in fields" :key="f.id" type="button" :aria-pressed="weekField?.id === f.id" data-test="lane-toggle" @click="weekCourt = f.id">
                    <i :class="['fdot', dotOf(f.size)]" />{{ courtName(f) }}
                </button>
            </div>
            <div class="hgwrap" data-test="week-grid">
                <HourGrid
                    ref="grid"
                    :cols="weekCols"
                    :bookings="shownRows(week.bookings)"
                    :closures="week.closures"
                    :hours="weekHours"
                    :from="weekRange[0]"
                    :to="weekRange[1]"
                    :now="now"
                    :today="today"
                    :step="week.step"
                    :pxh="48"
                    compact
                    :state-of="stateOf"
                    :label="nameOf"
                    :colors="owner.colors"
                    @open="openBooking"
                    @free="newFree"
                />
            </div>
        </template>

        <!-- list -->
        <template v-else-if="view === 'list' && list">
            <div class="toolbar">
                <div class="search">
                    <Icon name="search" /><input v-model="search" class="input" :placeholder="$t('owner.schedule.search')" data-test="search" />
                </div>
                <div class="chips">
                    <button
                        v-for="k in ['pending', 'accepted', 'cancelled', 'all']"
                        :key="k"
                        type="button"
                        class="chip"
                        :aria-pressed="status === k"
                        @click="setStatus(k)"
                    >
                        {{ $t(`owner.schedule.filter_${k}`) }} <span class="faint">{{ listAll.filter(FILTERS[k]).length }}</span>
                    </button>
                </div>
            </div>
            <p class="faint" style="font-size: 12.5px; margin: 0 0 10px">
                {{ $t('owner.schedule.range') }} · {{ $t('owner.schedule.shown', { n: Math.min(listShown.length, 200), m: listShown.length }) }}
            </p>
            <div class="card blist">
                <div v-for="b in listShown.slice(0, 200)" :key="b.id" :class="['brow', { past: b.past }]" data-test="row" @click="openBooking(b.id)">
                    <div class="d">
                        <b>{{ relDay(b.date, today) }}</b
                        >{{ hhmm(b.start) }}–{{ hhmm(b.end) }}
                    </div>
                    <div class="n">
                        <b>{{ b.name }}</b
                        ><small>{{ b.team ? b.team + ' · ' : '' }}{{ b.phone || '' }}</small>
                    </div>
                    <div class="f">
                        <i :class="['fdot', dotOf(fieldOf(b.field_id)?.size || 'small')]" /><span
                            >{{ fieldOf(b.field_id)?.label }}<br /><small class="mono">{{ b.code }}</small></span
                        >
                    </div>
                    <div class="pr">{{ amd(b.price) }}</div>
                    <div class="acts">
                        <template v-if="kindOf(b) === 'pending'">
                            <button type="button" class="btn sm" @click.stop="accept(b.id, b)">{{ $t('common.accept') }}</button>
                            <button type="button" class="btn sm danger" @click.stop="askDecline(b.id, whoOf(b))">{{ $t('common.decline') }}</button>
                        </template>
                        <span v-else :class="['pill', rowPill(b).cls]">{{ rowPill(b).label }}</span>
                    </div>
                </div>
                <div v-if="!listShown.length" class="empty" style="border: 0">{{ $t('owner.schedule.none') }}</div>
            </div>
        </template>
    </OwnerLayout>
</template>

<style scoped>
.hgwrap { margin: 0 -4px 14px; }
@media (max-width: 900px) { .hgwrap { --hg-top: 61px; } }
.hglegend { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 12px; color: var(--muted); margin: 0 0 16px; }
.hglegend span { display: inline-flex; align-items: center; gap: 6px; }
.hglegend i { width: 14px; height: 10px; border-radius: 3px; display: inline-block; }
.hglegend .lf { border: 1.5px dashed rgba(94, 240, 168, 0.6); background: rgba(94, 240, 168, 0.1); }
</style>
