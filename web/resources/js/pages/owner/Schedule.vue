<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import {
    accept,
    addDays,
    askDecline,
    daysBetween,
    dotOf,
    fdate,
    longDate,
    openBooking,
    openForm,
    pillOf,
    relDay,
    useOwner,
    type BookingRow,
} from '@/composables/useOwner';
import { amd, hhmm } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { router, usePoll } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Schedule: day (fields side by side), week (7 days × fields) and list (search + status filter).
interface Closure {
    id: number;
    field_id: number | null;
    reason: string | null;
    start: number;
    end: number;
}
interface WeekBlock {
    id: number;
    start: number;
    end: number;
    kind: string;
    name: string;
}
const props = defineProps<{
    view: 'day' | 'week' | 'list';
    date: string;
    today: string;
    nowHour: number;
    strip: string[];
    pendingDays: string[];
    day?: { open: number; close: number; closed: boolean; hours: [number, number] | null; bookings: BookingRow[]; closures: Closure[] };
    week?: {
        days: {
            date: string;
            hours: [number, number] | null;
            fields: Record<number, { blocks: WeekBlock[]; count: number; pending: number; pct: number }>;
        }[];
    };
    list?: { from: string; to: string; rows: BookingRow[] };
}>();
const owner = useOwner();
const fields = computed(() => owner.value.fields);
usePoll(30000, { only: ['day', 'week', 'list', 'pendingDays', 'owner', 'nowHour'] });

const PX = 60; // pixels per hour
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

// ---------- day view ----------
const sw = ref<HTMLElement>();
const dayRange = computed(() => (props.day ? [props.day.open, props.day.close] : [8, 24]));
const slots = computed(() => {
    const [o, c] = dayRange.value;
    const out: { t: number; past: boolean }[] = [];
    for (let t = o; t < c; t += 0.5) out.push({ t, past: isPastDay.value || (isToday.value && t + 0.5 <= now.value) });
    return out;
});
const blocksOf = (fid: number) =>
    (props.day?.bookings ?? []).filter((b) => b.field_id === fid && b.date === props.date && (showOff.value || b.kind !== 'off'));
const closuresOf = (fid: number) => (props.day?.closures ?? []).filter((c) => c.field_id === null || c.field_id === fid);
const top = (h: number) => (h - dayRange.value[0]) * PX;
function headOf(fid: number) {
    const list = (props.day?.bookings ?? []).filter((b) => b.field_id === fid && ['pending', 'accepted', 'completed', 'no_show'].includes(b.status));
    return { h: +list.reduce((s, b) => s + b.minutes / 60, 0).toFixed(1), n: list.length, p: list.filter((b) => b.status === 'pending').length };
}
function scrollDay() {
    const el = sw.value;
    if (!el || !props.day) return;
    const o = props.day.open;
    const first = props.day.bookings
        .filter((b) => b.kind !== 'off')
        .map((b) => b.start)
        .sort((a, b) => a - b)[0];
    const target = isToday.value && now.value > o ? now.value - 1 : first != null ? first - 0.5 : o;
    el.scrollTop = Math.max(0, (target - o) * PX);
}
onMounted(scrollDay);
watch(
    () => [props.date, props.view],
    () => nextTick(scrollDay),
);
function newAt(fid: number, t: number) {
    openForm({ field_id: fid, date: props.date, start: t });
}

// ---------- week view ----------
const weekSpan = computed(() => {
    const hs = (props.week?.days ?? []).map((d) => d.hours).filter(Boolean) as [number, number][];
    return hs.length ? [Math.min(...hs.map((h) => h[0])), Math.max(...hs.map((h) => h[1]))] : [8, 24];
});
const wpos = (b: WeekBlock) => {
    const [o, c] = weekSpan.value;
    return { left: ((b.start - o) / (c - o)) * 100 + '%', width: ((b.end - b.start) / (c - o)) * 100 + '%' };
};
const scale = computed(() => {
    const [o, c] = weekSpan.value;
    return [0, 0.25, 0.5, 0.75, 1].map((k) => hhmm(Math.round(o + (c - o) * k)));
});

// ---------- list view ----------
const qs = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
const search = ref('');
const status = ref<string>(['pending', 'accepted', 'cancelled', 'all'].includes(qs.get('status') || '') ? (qs.get('status') as string) : 'all');
const FILTERS: Record<string, (b: BookingRow) => boolean> = {
    pending: (b) => b.status === 'pending',
    accepted: (b) => b.status === 'accepted' || b.status === 'completed',
    cancelled: (b) => ['declined', 'cancelled', 'expired', 'no_show'].includes(b.status),
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
const legend = computed(() => [
    ['pending', trans('owner.st.pending')],
    ['accepted', trans('owner.st.accepted')],
    ['owner', trans('owner.st.owner')],
    ['off', `${trans('owner.st.declined')} / ${trans('owner.st.cancelled')}`],
    ['closed', trans('owner.schedule.closure')],
]);
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.schedule')">
        <div class="phead">
            <div>
                <div class="kicker">{{ $t('owner.schedule.kicker') }} · {{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.schedule') }}</h1>
            </div>
            <div class="phead-r">
                <button type="button" class="btn" data-test="new" @click="openForm({ date: daysBetween(today, date) >= 0 ? date : today })">
                    <Icon name="plus" size="sm" />{{ $t('owner.new_booking') }}
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
            <label v-if="view === 'day'" class="switch"><input v-model="showOff" type="checkbox" />{{ $t('owner.schedule.show_off') }}</label>
        </div>

        <!-- date bar -->
        <div v-if="view !== 'list'" class="datebar">
            <button
                type="button"
                class="x"
                style="margin: 0"
                :aria-label="$t('owner.schedule.prev')"
                @click="go({ date: addDays(date, view === 'week' ? -7 : -1) })"
            >
                <Icon name="chevL" size="sm" />
            </button>
            <div ref="chips" class="chips">
                <button v-for="d in strip" :key="d" type="button" class="chip dchip" :aria-pressed="d === date" @click="go({ date: d })">
                    <small>{{ d === today ? $t('owner.today') : fdate(d, { weekday: 'short' }) }}</small
                    ><b>{{ fdate(d, { day: 'numeric' }) }}</b
                    ><i v-if="pendingDays.includes(d)" class="dp" />
                </button>
            </div>
            <button
                type="button"
                class="x"
                style="margin: 0"
                :aria-label="$t('owner.schedule.next')"
                @click="go({ date: addDays(date, view === 'week' ? 7 : 1) })"
            >
                <Icon name="chevR" size="sm" />
            </button>
            <button v-if="date !== today" type="button" class="btn ghost sm" @click="go({ date: today })">{{ $t('owner.today') }}</button>
            <input
                class="input"
                type="date"
                :value="date"
                :aria-label="$t('owner.schedule.goto')"
                @change="(e) => (e.target as HTMLInputElement).value && go({ date: (e.target as HTMLInputElement).value })"
            />
        </div>

        <!-- day -->
        <template v-if="view === 'day' && day">
            <h2 style="font-size: 18px; margin: 4px 0 12px">
                {{ relDay(date, today) }}<template v-if="Math.abs(daysBetween(today, date)) <= 1"> · {{ longDate(date) }}</template>
            </h2>
            <div v-if="day.closed" class="closed-note"><Icon name="clock" size="sm" />{{ $t('owner.schedule.closed_day') }}</div>
            <div ref="sw" class="sched-wrap">
                <div class="sched" :style="{ gridTemplateColumns: `58px repeat(${fields.length}, minmax(220px, 1fr))` }">
                    <div class="colh" style="padding: 0" />
                    <div v-for="f in fields" :key="'h' + f.id" class="colh">
                        <i :class="['fdot', dotOf(f.size)]" />
                        <span style="min-width: 0">
                            <b>{{ f.label }}</b>
                            <small
                                >{{ $t('owner.schedule.booked', { h: headOf(f.id).h, n: headOf(f.id).n })
                                }}<template v-if="headOf(f.id).p">
                                    · <span style="color: var(--warn)">{{ $t('owner.schedule.pending_n', { n: headOf(f.id).p }) }}</span></template
                                ></small
                            >
                        </span>
                    </div>
                    <div class="times" :style="{ height: (dayRange[1] - dayRange[0]) * PX + 'px' }">
                        <div v-for="t in dayRange[1] - dayRange[0] + 1" :key="t" :style="{ top: (t - 1) * PX + 'px' }">
                            {{ t - 1 + dayRange[0] < dayRange[1] ? hhmm(t - 1 + dayRange[0]) : '' }}
                        </div>
                    </div>
                    <div
                        v-for="f in fields"
                        :key="'c' + f.id"
                        class="col"
                        :data-field="f.id"
                        :style="{ height: (dayRange[1] - dayRange[0]) * PX + 'px' }"
                    >
                        <button
                            v-for="s in slots"
                            :key="s.t"
                            type="button"
                            :class="['slot', { half: s.t % 1, past: s.past }]"
                            :style="{ top: top(s.t) + 'px', height: PX / 2 + 'px' }"
                            :tabindex="s.past ? -1 : undefined"
                            :aria-label="s.past ? undefined : `${$t('owner.new_booking')} ${hhmm(s.t)}`"
                            @click="!s.past && newAt(f.id, s.t)"
                        >
                            {{ s.past ? '' : '+ ' + hhmm(s.t) }}
                        </button>
                        <div
                            v-for="c in closuresOf(f.id)"
                            :key="'z' + c.id"
                            class="blk closed"
                            :style="{ top: top(c.start) + 1 + 'px', height: (c.end - c.start) * PX - 3 + 'px' }"
                        >
                            <div class="bi">
                                <div class="bt">{{ hhmm(c.start) }}–{{ hhmm(c.end) }}</div>
                                <div class="bn" style="color: var(--hot)">{{ $t('owner.schedule.closure') }}</div>
                                <div class="bx">{{ c.reason }}</div>
                            </div>
                        </div>
                        <div v-if="isToday && now > dayRange[0] && now < dayRange[1]" class="nowline" :style="{ left: 0, top: top(now) + 'px' }" />
                        <div
                            v-for="b in blocksOf(f.id)"
                            :key="b.id"
                            :class="['blk', b.kind, { done: b.past && b.kind !== 'off' }]"
                            role="button"
                            tabindex="0"
                            data-test="block"
                            :style="{ top: top(b.start) + 1 + 'px', height: (b.end - b.start) * PX - 3 + 'px' }"
                            @click="openBooking(b.id)"
                            @keydown.enter.self="openBooking(b.id)"
                        >
                            <div class="bi">
                                <div class="bt">
                                    {{ hhmm(b.start) }}–{{ hhmm(b.end)
                                    }}<template v-if="b.kind === 'off' || b.kind === 'noshow'"> · {{ pillOf(b).label }}</template>
                                </div>
                                <div class="bn">{{ b.team || b.name }}</div>
                                <div v-if="b.minutes >= 60" class="bx">{{ b.team ? b.name : b.phone || '' }}</div>
                            </div>
                            <div v-if="b.kind === 'pending' && !b.started" class="ba">
                                <button type="button" class="ok" :title="$t('owner.a.accept')" data-test="block-accept" @click.stop="accept(b.id)">
                                    <Icon name="check" size="sm" /><span>{{ $t('owner.a.accept') }}</span>
                                </button>
                                <button type="button" class="no" :title="$t('owner.a.decline')" @click.stop="askDecline(b.id)">
                                    <Icon name="x" size="sm" /><span>{{ $t('owner.a.decline') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="legend-row">
                <span v-for="l in legend" :key="l[0]"><i :class="l[0]" />{{ l[1] }}</span>
                <span class="faint" style="margin-left: auto">{{ $t('owner.schedule.hint') }}</span>
            </div>
        </template>

        <!-- week -->
        <template v-else-if="view === 'week' && week">
            <div class="sched-wrap" style="max-height: none; min-height: 0">
                <div class="week" :style="{ gridTemplateColumns: `96px repeat(${fields.length}, 1fr)` }">
                    <div class="wh" />
                    <div v-for="f in fields" :key="'wh' + f.id" class="wh"><i :class="['fdot', dotOf(f.size)]" />{{ f.label }}</div>
                    <template v-for="d in week.days" :key="d.date">
                        <div :class="['wd', { today: d.date === today }]" style="cursor: pointer" @click="go({ date: d.date, view: 'day' })">
                            <b>{{ d.date === today ? $t('owner.today') : fdate(d.date, { weekday: 'short' }) }}</b
                            ><span class="muted">{{ fdate(d.date, { day: 'numeric', month: 'short' }) }}</span>
                        </div>
                        <div
                            v-for="f in fields"
                            :key="d.date + f.id"
                            :class="['wc', { closedday: !d.hours }]"
                            data-test="week-cell"
                            @click="go({ date: d.date, view: 'day' })"
                        >
                            <div class="strip">
                                <i
                                    v-for="b in d.fields[f.id]?.blocks ?? []"
                                    :key="b.id"
                                    :class="b.kind"
                                    :style="wpos(b)"
                                    :title="`${hhmm(b.start)} ${b.name}`"
                                />
                            </div>
                            <div class="wmeta">
                                <span>{{
                                    d.hours ? `${d.fields[f.id]?.pct ?? 0}% · ${d.fields[f.id]?.count ?? 0}` : $t('owner.schedule.closed_short')
                                }}</span>
                                <span v-if="d.fields[f.id]?.pending" class="p">{{
                                    $t('owner.schedule.pending_n', { n: d.fields[f.id].pending })
                                }}</span>
                            </div>
                        </div>
                    </template>
                    <div />
                    <div v-for="(f, i) in fields" :key="'sc' + f.id" :style="i ? 'border-left: 1px solid var(--line)' : ''">
                        <div class="wscale">
                            <span v-for="s in scale" :key="s">{{ s }}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="legend-row">
                <span v-for="l in legend.slice(0, 3)" :key="l[0]"><i :class="l[0]" />{{ l[1] }}</span>
                <span class="faint" style="margin-left: auto">{{ $t('owner.schedule.week_hint') }}</span>
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
                        {{ $t(`owner.schedule.f_${k}`) }} <span class="faint">{{ listAll.filter(FILTERS[k]).length }}</span>
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
                        <template v-if="b.status === 'pending' && !b.started">
                            <button type="button" class="btn sm" @click.stop="accept(b.id)">{{ $t('owner.a.accept') }}</button>
                            <button type="button" class="btn sm danger" @click.stop="askDecline(b.id)">{{ $t('owner.a.decline') }}</button>
                        </template>
                        <span v-else :class="['pill', pillOf(b).cls]">{{ pillOf(b).label }}</span>
                    </div>
                </div>
                <div v-if="!listShown.length" class="empty" style="border: 0">{{ $t('owner.schedule.none') }}</div>
            </div>
        </template>
    </OwnerLayout>
</template>
