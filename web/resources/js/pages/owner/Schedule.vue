<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import {
    accept,
    askDecline,
    daysBetween,
    decisions,
    dotOf,
    dur,
    fdate,
    longDate,
    openBooking,
    openForm,
    pillOf,
    relDay,
    useOwner,
    whoOf,
    type BookingRow,
    type Kind,
} from '@/composables/useOwner';
import { amd, hhmm } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { router, usePoll } from '@inertiajs/vue3';
import { trans } from '@/lib/i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Schedule: day (fields side by side; one field at a time below 768px), week (7 days × fields) and list (search + status filter).
interface Closure {
    id: number;
    field_id: number | null;
    reason: string | null;
    start: number;
    end: number;
}
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
        closures: Closure[];
        lanes: Lane[];
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
const lane = ref(Number(read('bb.owner.lane', '0')) || 0);
watch(lane, (v) => {
    try {
        localStorage.setItem('bb.owner.lane', String(v));
    } catch {
        /* private mode */
    }
});
// the columns: each football field, all tables together
const lanes = computed<Lane[]>(
    () =>
        props.day?.lanes ??
        props.week?.lanes ??
        fields.value.map((f) => ({ id: f.id, size: f.size, sport: f.sport ?? 'football', count: 1, label: f.label, short: f.short ?? f.format, courts: [f.id] })),
);
const shownLanes = computed(() => {
    if (!narrow.value) return lanes.value;
    const l = lanes.value.find((x) => x.id === lane.value) ?? lanes.value[0];
    return l ? [l] : [];
});
const inLane = (b: { field_id: number }, l: Lane) => l.courts.includes(b.field_id);
const laneWaiting = (l: Lane) => (props.day?.bookings ?? []).filter((b) => inLane(b, l) && b.kind === 'pending' && !decisions.hidden[b.id]).length;
/** "Table 2" on a booking in the tables column */
const courtOf = (id: number) => fields.value.find((f) => f.id === id)?.short ?? owner.value.fields.find((f) => f.id === id)?.short ?? '';

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
const isPastDay = computed(() => daysBetween(props.today, props.date) < 0);

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

// ---------- day view: one list per field, top to bottom (like the booking page) ----------
// free gaps come from the server with the same rules as the booking page; past hours of today fold into "Earlier today"
type Item =
    | { type: 'b'; key: string; start: number; end: number; b: BookingRow }
    | { type: 'free'; key: string; start: number; end: number; busy: number; taken: number[] }
    | { type: 'full'; key: string; start: number; end: number }
    | { type: 'closed'; key: string; start: number; end: number; c: Closure }
    | { type: 'now'; key: string; start: number; end: number };
// answered on this device but still inside the Undo window
const kindOf = (b: BookingRow): Kind => (decisions.hidden[b.id] === 'accepted' ? 'accepted' : decisions.hidden[b.id] === 'declined' ? 'off' : b.kind);
// expired, declined and cancelled don't hold the time (players can book it): hidden unless "Show…" is on
const isOff = (b: BookingRow) => ['off', 'expired'].includes(kindOf(b));
const closuresOf = (l: Lane) => (props.day?.closures ?? []).filter((c) => c.field_id === null || l.courts.includes(c.field_id));
// shorter than this is not worth a "Free" row (the place's time step)
const MIN_FREE = 0.25 - 1e-9;
function itemsOf(l: Lane): { earlier: Item[]; later: Item[] } {
    const d = props.day;
    if (!d) return { earlier: [], later: [] };
    const segs = d.load[l.id] ?? [];
    const list: Item[] = [
        ...d.bookings
            .filter((b) => inLane(b, l) && b.date === props.date && (showOff.value || !isOff(b)))
            .map((b) => ({ type: 'b' as const, key: 'b' + b.id, start: b.start, end: b.end, b })),
        // free while at least one court is free; with several tables also "all taken" stretches (3/3)
        ...segs
            .filter(([s0, e0, n]) => n < l.count && e0 - s0 >= MIN_FREE)
            .map(([s0, e0, n, taken]) => ({ type: 'free' as const, key: 'f' + s0, start: s0, end: e0, busy: n, taken: taken ?? [] })),
        ...(l.count > 1
            ? segs.filter(([, , n]) => n >= l.count).map(([s0, e0]) => ({ type: 'full' as const, key: 'u' + s0, start: s0, end: e0 }))
            : []),
        ...closuresOf(l)
            .filter((c) => l.count === 1 || c.field_id === null)
            .map((c) => ({ type: 'closed' as const, key: 'z' + c.id, start: c.start, end: c.end, c })),
    ].sort((x, y) => x.start - y.start || (x.type === 'b' && isOff(x.b) ? 1 : 0) - (y.type === 'b' && isOff(y.b) ? 1 : 0));
    if (isPastDay.value) return { earlier: [], later: list };
    if (!isToday.value) return { earlier: [], later: list };
    const earlier = list.filter((i) => i.end <= now.value + 1e-9);
    const later = list.filter((i) => i.end > now.value + 1e-9);
    // the now line: after what is going on right now, before what starts later
    const at = later.findIndex((i) => i.start > now.value);
    later.splice(at < 0 ? later.length : at, 0, { type: 'now', key: 'now', start: now.value, end: now.value });
    return { earlier, later };
}
const earlierOpen = ref(false);
watch(
    () => props.date,
    () => (earlierOpen.value = false),
);
const earlierCount = (items: Item[]) => items.filter((i) => i.type === 'b' && !isOff(i.b)).length;
function headOf(l: Lane) {
    const list = (props.day?.bookings ?? []).filter((b) => inLane(b, l) && ['pending', 'accepted', 'owner', 'noshow'].includes(kindOf(b)));
    return { h: +list.reduce((s, b) => s + b.minutes / 60, 0).toFixed(1), n: list.length, p: list.filter((b) => kindOf(b) === 'pending').length };
}
// "Playing now" / "by you · every week" / "sent 12 min ago" under the name
function subOf(b: BookingRow): string {
    const bits: string[] = [];
    if (b.team && b.name) bits.push(b.name);
    if (b.weekly) bits.push(trans('owner.schedule.weekly'));
    if (kindOf(b) === 'pending' && b.sent_ago) bits.push(b.sent_ago);
    if (b.phone) bits.push(b.phone);
    return bits.join(' · ');
}
const pillFor = (b: BookingRow) =>
    b.started && !b.past && ['accepted', 'owner'].includes(kindOf(b)) ? { cls: 'green', label: trans('owner.schedule.playing') } : rowPill(b);
const lenOf = (a: number, b: number) => dur(Math.round((b - a) * 60));
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
    document.querySelector('.drow.now')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
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
// "+ Book" on the tables column: any free table (the owner can still pick one in the form)
function newAt(l: Lane, t: number) {
    openForm({ field_id: l.id, date: props.date, start: t, any: l.count > 1 });
}

// ---------- week view: one card per day with its free time, like the booking page ----------
const weekPast = (d: string) => daysBetween(props.today, d) < 0;
const gapText = (g: [number, number]) => `${hhmm(g[0])}–${hhmm(g[1])}`;

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

const fieldOf = (id: number) => fields.value.find((f) => f.id === id);
const rowPill = (b: BookingRow) => pillOf({ kind: kindOf(b), status: decisions.hidden[b.id] ?? b.status });
const WEEK_CHIPS = 4;
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

        <!-- phones: one field at a time -->
        <div v-if="narrow && view === 'day' && lanes.length > 1" class="seg lanesw" role="group" :aria-label="$t('common.field')">
            <button
                v-for="l in lanes"
                :key="l.id"
                type="button"
                :aria-pressed="shownLanes[0]?.id === l.id"
                data-test="lane-toggle"
                @click="lane = l.id"
            >
                <i :class="['fdot', dotOf(l.size)]" />{{ l.short }}<span v-if="laneWaiting(l)" class="lw">{{ laneWaiting(l) }}</span>
            </button>
        </div>

        <!-- day: the day as a list per field (fields side by side on a computer) -->
        <template v-if="view === 'day' && day">
            <h2 style="font-size: 18px; margin: 4px 0 12px">
                {{ relDay(date, today) }}<template v-if="Math.abs(daysBetween(today, date)) <= 1"> · {{ longDate(date) }}</template>
            </h2>
            <div v-if="day.closed" class="closed-note"><Icon name="clock" size="sm" />{{ $t('owner.schedule.closed_day') }}</div>
            <div class="dlists" :style="{ gridTemplateColumns: `repeat(${shownLanes.length}, minmax(0, 1fr))` }">
                <section v-for="f in shownLanes" :key="'d' + f.id" class="dlist" :data-field="f.id">
                    <header class="dhead">
                        <i :class="['fdot', dotOf(f.size)]" />
                        <b>{{ f.label }}</b><span v-if="f.count > 1" class="pill">{{ f.count }}</span>
                        <small
                            >{{ $t('owner.schedule.booked', { h: String(headOf(f).h), n: String(headOf(f).n) })
                            }}<template v-if="headOf(f).p">
                                · <span class="p">{{ $t('owner.schedule.pending_count', { n: String(headOf(f).p) }) }}</span></template
                            ></small
                        >
                    </header>
                    <template v-for="part in [itemsOf(f)]" :key="part.later.length + '-' + part.earlier.length">
                        <button v-if="part.earlier.length" type="button" class="earlier" :aria-expanded="earlierOpen" @click="earlierOpen = !earlierOpen">
                            <span>{{ $t('owner.schedule.earlier') }}</span>
                            <span
                                >{{ $t('owner.schedule.earlier_n', { n: String(earlierCount(part.earlier)) }) }}
                                <Icon name="arrow" size="sm" :class="['chev', { flip: earlierOpen }]"
                            /></span>
                        </button>
                        <div v-if="part.earlier.length && earlierOpen" class="drows was">
                            <template v-for="i in part.earlier" :key="i.key">
                                <div v-if="i.type === 'b'" :class="['drow bk', kindOf(i.b), { off: isOff(i.b) }]" role="button" tabindex="0" data-test="block" @click="openBooking(i.b.id)" @keydown.enter.self="openBooking(i.b.id)">
                                    <span class="tm">{{ hhmm(i.start) }}–{{ hhmm(i.end) }}<small>{{ lenOf(i.start, i.end) }}</small></span>
                                    <span class="what"
                                        ><b
                                            ><span v-if="f.count > 1" class="ctag">{{ courtOf(i.b.field_id) }}</span
                                            ><span v-if="i.b.purpose === 'training'" class="ctag tr">{{ $t('club.training') }}</span>{{ i.b.team || i.b.name || '—' }}<Icon
                                                v-if="i.b.paid"
                                                name="check"
                                                size="sm"
                                                class="paidmark"
                                                :title="$t('owner.club.paid')"
                                        /></b
                                        ><small>{{ subOf(i.b) }}{{ subOf(i.b) ? ' · ' : '' }}{{ amd(i.b.price) }}</small></span
                                    >
                                    <span :class="['pill', pillFor(i.b).cls]">{{ pillFor(i.b).label }}</span>
                                </div>
                                <div v-else-if="i.type === 'closed'" class="drow closed">
                                    <span class="tm">{{ hhmm(i.start) }}–{{ hhmm(i.end) }}</span>
                                    <span class="what"><b>{{ $t('common.closed') }}</b><small>{{ i.c.reason }}</small></span>
                                </div>
                            </template>
                        </div>
                        <div v-if="part.later.length" class="drows">
                            <template v-for="i in part.later" :key="i.key">
                                <div v-if="i.type === 'now'" class="drow now">{{ hhmm(now) }}</div>
                                <button v-else-if="i.type === 'free'" type="button" class="drow free" data-test="free" @click="newAt(f, i.start)">
                                    <span class="tm">{{ hhmm(i.start) }}–{{ hhmm(i.end) }}<small>{{ $t('owner.schedule.free_len', { d: lenOf(i.start, i.end) }) }}</small></span>
                                    <span class="what"
                                        ><b>{{ f.count > 1 ? $t('owner.schedule.tables_free', { n: String(f.count - i.busy) }) : $t('owner.schedule.free_row') }}</b
                                        ><small v-if="f.count > 1"
                                            >{{
                                                i.taken.length
                                                    ? $t('owner.schedule.free_tables', { list: f.courts.filter((c) => !i.taken.includes(c)).map(courtOf).join(', ') })
                                                    : $t('owner.schedule.all_free')
                                            }}</small
                                        ><!-- each table by its number: green free, orange taken -->
                                        <span v-if="f.count > 1" class="tbls">
                                            <i v-for="(c, k) in f.courts" :key="c" :class="{ on: i.taken.includes(c) }" :title="courtOf(c)">{{ k + 1 }}</i>
                                        </span></span
                                    >

                                    <span class="add"><Icon name="plus" size="sm" />{{ $t('owner.schedule.book') }}</span>
                                </button>
                                <div v-else-if="i.type === 'closed'" class="drow closed">
                                    <span class="tm">{{ hhmm(i.start) }}–{{ hhmm(i.end) }}</span>
                                    <span class="what"><b>{{ $t('common.closed') }}</b><small>{{ i.c.reason }}</small></span>
                                </div>
                                <div v-else-if="i.type === 'full'" class="drow full">
                                    <span class="tm">{{ hhmm(i.start) }}–{{ hhmm(i.end) }}</span>
                                    <span class="what"><b>{{ $t('owner.schedule.all_taken') }}</b></span>
                                    <span class="pill">{{ f.count }}/{{ f.count }}</span>
                                </div>
                                <div
                                    v-else-if="i.type === 'b'"
                                    :class="['drow bk', kindOf(i.b), { off: isOff(i.b), done: i.b.past }]"
                                    role="button"
                                    tabindex="0"
                                    data-test="block"
                                    @click="openBooking(i.b.id)"
                                    @keydown.enter.self="openBooking(i.b.id)"
                                >
                                    <span class="tm">{{ hhmm(i.start) }}–{{ hhmm(i.end) }}<small>{{ lenOf(i.start, i.end) }}</small></span>
                                    <span class="what"
                                        ><b
                                            ><span v-if="f.count > 1" class="ctag">{{ courtOf(i.b.field_id) }}</span
                                            ><span v-if="i.b.purpose === 'training'" class="ctag tr">{{ $t('club.training') }}</span>{{ i.b.team || i.b.name || '—' }}<Icon
                                                v-if="i.b.paid"
                                                name="check"
                                                size="sm"
                                                class="paidmark"
                                                :title="$t('owner.club.paid')"
                                        /></b
                                        ><small>{{ subOf(i.b) }}{{ subOf(i.b) ? ' · ' : '' }}{{ amd(i.b.price) }}</small></span
                                    >
                                    <span :class="['pill', pillFor(i.b).cls]">{{ pillFor(i.b).label }}</span>
                                    <div v-if="kindOf(i.b) === 'pending'" class="acts">
                                        <button type="button" class="btn sm" data-test="block-accept" @click.stop="accept(i.b.id, i.b)">
                                            <Icon name="check" size="sm" />{{ $t('common.accept') }}
                                        </button>
                                        <button type="button" class="btn sm danger" @click.stop="askDecline(i.b.id, whoOf(i.b))">
                                            <Icon name="x" size="sm" />{{ $t('common.decline') }}
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <p v-if="!day.closed && !part.later.some((i) => i.type === 'free') && !isPastDay" class="faint dnone">{{ $t('owner.schedule.no_free_left') }}</p>
                    </template>
                </section>
            </div>
        </template>

        <!-- week: one card per day; free time as green chips like the booking page (tap = book), the card opens the day -->
        <template v-else-if="view === 'week' && week">
            <div class="wcards">
                <article v-for="d in week.days" :key="d.date" :class="['wcard', { today: d.date === today, was: weekPast(d.date) }]" data-test="week-cell" @click="go({ date: d.date, view: 'day' })">
                    <header>
                        <b>{{ Math.abs(daysBetween(today, d.date)) <= 1 ? relDay(d.date, today) : fdate(d.date, { weekday: 'long' }) }}</b>
                        <span class="muted">{{ fdate(d.date, { day: 'numeric', month: 'short' }) }}</span>
                        <Icon name="arrow" size="sm" class="go" />
                    </header>
                    <div v-if="!d.hours" class="faint wline">{{ $t('owner.schedule.closed_day') }}</div>
                    <template v-else>
                        <div v-for="f in lanes" :key="d.date + f.id" class="wf">
                            <div class="wfh">
                                <i :class="['fdot', dotOf(f.size)]" /><b>{{ f.short }}</b><span v-if="f.count > 1" class="faint">· {{ f.count }}</span>
                                <span class="muted">{{ $t('owner.schedule.bookings_n', { n: String(d.fields[f.id]?.count ?? 0) }) }}</span>
                                <span v-if="d.fields[f.id]?.pending" class="pill warn">{{ $t('owner.schedule.pending_count', { n: String(d.fields[f.id].pending) }) }}</span>
                            </div>
                            <div v-if="weekPast(d.date)" class="faint wline">{{ $t('owner.schedule.past_day') }}</div>
                            <div v-else-if="d.fields[f.id]?.free?.length" class="wfree">
                                <button
                                    v-for="g in d.fields[f.id].free.slice(0, WEEK_CHIPS)"
                                    :key="g[0]"
                                    type="button"
                                    class="fchip"
                                    @click.stop="openForm({ field_id: f.id, date: d.date, start: g[0], any: f.count > 1 })"
                                >
                                    {{ gapText(g) }}
                                </button>
                                <span v-if="d.fields[f.id].free.length > WEEK_CHIPS" class="faint more">{{ $t('owner.schedule.more_n', { n: String(d.fields[f.id].free.length - WEEK_CHIPS) }) }}</span>
                            </div>
                            <div v-else class="faint wline">{{ $t('owner.schedule.no_free_left') }}</div>
                        </div>
                    </template>
                </article>
            </div>
            <p class="faint" style="font-size: 12.5px; margin: 10px 0 0">{{ $t('owner.schedule.week_hint') }}</p>
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
