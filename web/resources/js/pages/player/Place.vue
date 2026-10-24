<script setup lang="ts">
import PlaceMap from '@/components/PlaceMap.vue';
import TelegramLogin from '@/components/TelegramLogin.vue';
import Icon from '@/components/Icon.vue';
import CourtSvg from '@/components/player/CourtSvg.vue';
import StatusPill from '@/components/player/StatusPill.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import { amd, hhmm, useShared } from '@/composables/useShared';
import { dateLong, dateRel, directionLinks, dur, nextLabel, pitchColor, toHours, wdName } from '@/composables/usePlayer';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { DayInfo, FieldInfo, PlayerBooking, SlotsPayload } from '@/types/player';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Place page: day → field → duration → start time (every start within the hours; taken ones greyed out),
// then a summary with the total and one "Book" button. On phones there is no tab bar on this page, and a bar with
// the time, price and the Book button appears at the bottom once a time is picked. Guests log in with Telegram in a sheet.
interface SlotAlertInfo {
    id: number;
    field: number;
    date: string;
    start: string;
    minutes: number;
    notified: boolean;
}
interface VenueInfo {
    id: number;
    slug: string;
    name: string;
    district: string;
    address: string | null;
    rating: number | null;
    reviews: number;
    description: string | null;
    amenities: string[];
    week: [number, number, number][];
    lat: number | null;
    lng: number | null;
    phone: string | null;
    phoneLabel: string | null;
    rules: { freeCancelHours: number; minNoticeMin: number; horizonDays: number };
}
const props = defineProps<{
    venue: VenueInfo;
    fields: FieldInfo[];
    days: DayInfo[];
    initial: { date: string; field: number | null; minutes: number | null; start: string | null };
    slots: SlotsPayload;
    booked: PlayerBooking | null;
    limit: { pending: number; maxPending: number; bannedUntil: string | null } | null;
    /** the player's "notify me if it frees up" times at this place */
    alerts: SlotAlertInfo[];
}>();
const { user } = useShared();
const today = computed(() => props.days[0]?.date);

// ---------- state ----------
const date = ref(props.initial.date);
const fieldId = ref<number | null>(props.initial.field ?? props.fields[0]?.id ?? null);
const minutes = ref<number>(props.initial.minutes ?? 60);
const start = ref<number | null>(null);
const slots = ref<SlotsPayload>(props.slots);
const loading = ref(false);
const showNote = ref(false);
const notice = ref<string | null>(null);

const field = computed(() => props.fields.find((f) => f.id === fieldId.value) ?? null);
const durFor = (f: FieldInfo) => (f.durations.includes(minutes.value) ? minutes.value : f.defaultDuration);
const startsFor = (f: FieldInfo | null, m: number) => (f ? (slots.value.fields.find((x) => x.id === f.id)?.starts[String(m)] ?? []) : []);
const free = computed(() => startsFor(field.value, minutes.value));
// prime times are marked only when the day has both prices (weekday daytime vs evening); at weekends every time
// is prime, and a free time must never look like a warning (amber is the "waiting" color)
const twoRates = computed(() => !!field.value && free.value.some((t) => isPrime(field.value!, t)) && free.value.some((t) => !isPrime(field.value!, t)));
const dayRate = computed(() => (field.value && free.value.length ? rateAt(field.value, free.value[0]) : 0));
const same = (a: number, b: number) => Math.abs(a - b) < 1e-6;
const slotRow = computed(() => (field.value ? slots.value.fields.find((x) => x.id === field.value!.id) : undefined));
/** every start time within the opening hours (past ones are not sent): free and taken, in order */
const allStarts = computed(() => {
    const taken = slotRow.value?.taken?.[String(minutes.value)] ?? [];
    return [...free.value.map((t) => ({ t, taken: false })), ...taken.map((t) => ({ t, taken: true }))].sort((a, b) => a.t - b.t);
});
/** nothing free this day: the first free start on a later day */
const nextFree = computed(() => (free.value.length ? null : (slotRow.value?.next?.[String(minutes.value)] ?? null)));

// prices: every half hour is charged by its own hourly rate (same rule as PriceCalculator)
function rateAt(f: FieldInfo, h: number): number {
    const r = slots.value.fields.find((x) => x.id === f.id)?.rates.find(([t]) => same(t, h));
    return r ? r[1] : f.price.max;
}
const isPrime = (f: FieldInfo, h: number) => rateAt(f, h) > f.price.min;
function priceOf(f: FieldInfo, s: number, m: number) {
    let total = 0;
    let dayMin = 0;
    let primeMin = 0;
    for (let k = 0; k < m; k += 30) {
        const h = s + k / 60;
        total += rateAt(f, h) / 2;
        if (isPrime(f, h)) primeMin += 30;
        else dayMin += 30;
    }
    return { total: Math.round(total), dayMin, primeMin };
}
const range = (s: number, m: number) => `${hhmm(s)}–${hhmm(s + m / 60)}`;
const cname = (f: FieldInfo) => `${trans(`player.size.${f.size}`)} ${f.format}`;

// ---------- choosing ----------
// The time is never picked for the player: only a time from the link (?start=) is preselected, and only when it is free.
function nearest(list: number[], t: number | null): number | null {
    if (!list.length) return null;
    if (t == null) return list[0];
    return list.reduce((a, b) => (Math.abs(b - t) < Math.abs(a - t) ? b : a));
}
const isFreeAt = (t: number) => free.value.some((x) => same(x, t));
/** keep the choice only while it is still free (after the day / duration / field changes) */
function revalidate() {
    if (start.value != null && !isFreeAt(start.value)) start.value = null;
}
function pickField(id: number) {
    lostStart.value = null;
    const f = props.fields.find((x) => x.id === id);
    if (!f) return;
    fieldId.value = id;
    if (!f.durations.includes(minutes.value)) minutes.value = f.defaultDuration;
    revalidate();
}
function pickDur(m: number) {
    minutes.value = m;
    revalidate();
}
function pickDay(d: string, wanted: number | null = start.value) {
    lostStart.value = null;
    if (d === date.value) {
        if (wanted != null && isFreeAt(wanted)) start.value = wanted;
        return;
    }
    date.value = d;
    nextTick(() => document.querySelector('.flow .days .day[aria-pressed="true"]')?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' }));
    load().then(() => {
        start.value = wanted != null && isFreeAt(wanted) ? wanted : null;
    });
}
/** "Fully booked. Next free: Fri 18:00 →" */
function goNext() {
    const n = nextFree.value;
    if (n) pickDay(n.date, n.start);
}
// a time from the link: keep it when it is free, else say it is gone
if (props.initial.start) {
    const t = toHours(props.initial.start);
    if (isFreeAt(t)) start.value = t;
    else if (free.value.length) notice.value = trans('player.place.refreshed');
}
// nothing free on the chosen field and the visitor did not ask for a field: show the field that has free times
if (!free.value.length && !new URLSearchParams(window.location.search).has('field')) {
    const other = props.fields.find((f) => startsFor(f, durFor(f)).length);
    if (other) pickField(other.id);
}

// ---------- live free times ----------
let seq = 0;
async function load(quiet = false) {
    const my = ++seq;
    if (!quiet) loading.value = true;
    try {
        const res = await fetch(route('places.slots', { venue: props.venue.slug, date: date.value }), { headers: { Accept: 'application/json' } });
        if (!res.ok || my !== seq) return;
        const data = (await res.json()) as SlotsPayload;
        if (data.date !== date.value) return;
        const before = start.value;
        slots.value = data;
        revalidate();
        if (quiet && before != null && (start.value == null || !same(before, start.value))) {
            // the picked time was just taken: offer "notify me if it frees up" for exactly that time
            const nowTaken = (slotRow.value?.taken?.[String(minutes.value)] ?? []).some((t) => same(t, before));
            lostStart.value = nowTaken ? before : null;
            notice.value = trans(nowTaken ? 'player.alert.justTaken' : 'player.place.refreshed');
        }
    } catch {
        // offline: keep what we have
    } finally {
        if (my === seq) loading.value = false;
    }
}
let timer: number | undefined;
const onVisible = () => document.visibilityState === 'visible' && load(true);
onMounted(() => {
    timer = window.setInterval(() => document.visibilityState === 'visible' && load(true), 60_000);
    document.addEventListener('visibilitychange', onVisible);
    if (props.booked) okBooking.value = props.booked;
});
onBeforeUnmount(() => {
    clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisible);
});
watch(
    () => props.slots,
    (s) => {
        if (s.date === date.value) {
            slots.value = s;
            revalidate();
        }
    },
);

// keep the URL shareable (date, field, duration, start) without a new history entry
watch([date, fieldId, minutes, start], () => {
    const u = new URL(window.location.href);
    u.searchParams.set('date', date.value);
    if (fieldId.value) u.searchParams.set('field', String(fieldId.value));
    u.searchParams.set('minutes', String(minutes.value));
    if (start.value != null) u.searchParams.set('start', hhmm(start.value));
    else u.searchParams.delete('start');
    u.searchParams.delete('size');
    window.history.replaceState(window.history.state, '', u.toString());
});

// ---------- field cards (status at the chosen time) ----------
type St = 'sel' | 'free' | 'busy';
const tiles = computed(() =>
    props.fields.map((f) => {
        const d = durFor(f);
        const list = startsFor(f, d);
        let st: St;
        let line = '';
        if (start.value == null) {
            st = list.length ? 'free' : 'busy';
            if (!list.length) line = trans('player.place.stNone');
        } else {
            const ok = list.some((t) => same(t, start.value!));
            st = ok ? (f.id === fieldId.value ? 'sel' : 'free') : 'busy';
            if (ok) line = `${amd(priceOf(f, start.value, d).total)} · ${dur(d)}`;
            else {
                const n = nearest(list, start.value);
                line = n != null ? trans('player.place.stNext', { t: hhmm(n) }) : trans('player.place.stNone');
            }
        }
        return { f, st, line };
    }),
);

// ---------- summary ----------
const summary = computed(() => {
    const f = field.value;
    if (!f || start.value == null) return null;
    const p = priceOf(f, start.value, minutes.value);
    const wd = props.days.find((d) => d.date === date.value);
    return { f, ...p, weekday: wd ? !wd.weekend : true };
});
const hoursToday = computed(() => slots.value.hours);
/** "Every day 08:00–24:00", or runs of days with the same hours: "Mon–Fri 08:00–24:00", "Sat–Sun 09:00–23:00" */
const weekHours = computed(() => {
    const w = props.venue.week;
    const span = (x?: [number, number, number]) => (x ? `${hhmm(x[1])}–${hhmm(x[2])}` : null);
    if (w.length === 7 && w.every((x) => x[1] === w[0][1] && x[2] === w[0][2])) return [{ label: trans('player.place.everyDay'), text: span(w[0])! }];
    const order = [1, 2, 3, 4, 5, 6, 0];
    const runs: { from: number; to: number; text: string | null }[] = [];
    for (const d of order) {
        const text = span(w.find((y) => y[0] === d));
        const last = runs[runs.length - 1];
        if (last && last.text === text) last.to = d;
        else runs.push({ from: d, to: d, text });
    }
    return runs.map((r) => ({ label: r.from === r.to ? wdName(r.from) : `${wdName(r.from)}–${wdName(r.to)}`, text: r.text ?? trans('player.place.closed') }));
});
const dirs = computed(() => directionLinks({ ...props.venue }));
const hasMap = computed(() => props.venue.lat != null && props.venue.lng != null);
const mapHeight = ref(260);
onMounted(() => {
    if (window.matchMedia('(max-width: 600px)').matches) mapHeight.value = 220;
});
const AMEN_ICON: Record<string, string> = { parking: 'car', showers: 'shower', lockers: 'locker', cafe: 'coffee', lights: 'bolt', bibs: 'ball', stands: 'users' };

// ---------- request ----------
const form = useForm({ field_id: 0, date: '', start: '', minutes: 60, team_name: '', note: '', name: '' });
// guests confirm who they are with Telegram (free) right in the sheet, then the request is sent
const sheet = ref<null | 'login' | 'name' | 'taken'>(null);
const okBooking = ref<PlayerBooking | null>(null);
const loginUrl = computed(() => route('login', { redirect: window.location.pathname + window.location.search }));

const bookError = computed(() => (lostStart.value == null ? (form.errors as Record<string, string>).booking ?? null : null));
// a new request can't be sent: say so before "Book" (G2), with the way out, instead of failing after the tap
const blocked = computed<string | null>(() => {
    const l = props.limit;
    if (!l) return null;
    if (l.bannedUntil) return trans('player.place.blockedBan', { until: l.bannedUntil });
    if (l.pending >= l.maxPending) return trans('player.place.blockedPending', { n: String(l.pending) });
    return null;
});

function book() {
    if (!summary.value || blocked.value) return;
    form.clearErrors();
    notice.value = null;
    if (!user.value) {
        afterLogin.value = 'book';
        sheet.value = 'login';
        return;
    }
    if (!user.value.name) {
        sheet.value = 'name';
        return;
    }
    submit();
}
function submit() {
    if (!field.value || start.value == null) return;
    form.field_id = field.value.id;
    form.date = date.value;
    form.start = hhmm(start.value);
    form.minutes = minutes.value;
    form.post(route('bookings.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            sheet.value = null;
            form.reset('team_name', 'note');
        },
        onError: (e) => {
            sheet.value = null;
            if ((e as Record<string, string>).booking) load(true);
        },
    });
}
// logged in through Telegram in the sheet: send the request (or the "notify me" alert) now
function onLoggedIn() {
    if (afterLogin.value === 'alert') watchTaken();
    else submit();
}

// ---------- "notify me if it frees up" for a taken time ----------
const takenAt = ref<number | null>(null);
/** the time the player had picked, just taken by someone else (refresh or a "taken" error) */
const lostStart = ref<number | null>(null);
const hasTaken = computed(() => allStarts.value.some((x) => x.taken));
// "find" the booked times: scroll to them with a short pulse
const pulse = ref(false);
function showTaken() {
    document.querySelector('.flow .slots')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    pulse.value = true;
    window.setTimeout(() => (pulse.value = false), 1600);
}
function watchLost() {
    if (lostStart.value == null) return;
    takenAt.value = lostStart.value;
    alertError.value = null;
    watchTaken();
}
// a one-time hint for logged-in players: "New: get notified when a booked time frees up"
const HINT = 'bb.alertHint';
const hintSeen = ref(true);
onMounted(() => {
    try {
        hintSeen.value = window.localStorage.getItem(HINT) === '1';
    } catch {
        hintSeen.value = true; // storage blocked: don't nag every visit
    }
});
function seeHint() {
    hintSeen.value = true;
    try {
        window.localStorage.setItem(HINT, '1');
    } catch {
        /* storage blocked */
    }
}
const showHint = computed(() => !!user.value && hasTaken.value && !hintSeen.value);
const afterLogin = ref<'book' | 'alert'>('book');
const alertBusy = ref(false);
const alertError = ref<string | null>(null);
const watchedAlert = (t: number) =>
    field.value ? (props.alerts ?? []).find((a) => a.field === field.value!.id && a.date === date.value && same(toHours(a.start), t)) : undefined;
function openTaken(t: number) {
    seeHint();
    takenAt.value = t;
    alertError.value = null;
    sheet.value = 'taken';
}
function watchTaken() {
    if (!field.value || takenAt.value == null) return;
    if (!user.value) {
        afterLogin.value = 'alert';
        sheet.value = 'login';
        return;
    }
    alertBusy.value = true;
    alertError.value = null;
    router.post(
        route('alerts.store'),
        { field_id: field.value.id, date: date.value, start: hhmm(takenAt.value), minutes: minutes.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                sheet.value = null;
                lostStart.value = null;
                notice.value = null;
            },
            onError: (e) => {
                alertError.value = e.alert ?? Object.values(e)[0] ?? null;
                sheet.value = 'taken';
                // "this time is free now": show the fresh times
                load(true);
            },
            onFinish: () => (alertBusy.value = false),
        },
    );
}
function unwatchTaken() {
    const a = takenAt.value != null ? watchedAlert(takenAt.value) : undefined;
    if (!a) return;
    alertBusy.value = true;
    router.delete(route('alerts.destroy', a.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (sheet.value = null),
        onFinish: () => (alertBusy.value = false),
    });
}
watch(
    () => props.booked,
    (b) => {
        if (b) okBooking.value = b;
    },
);
function closeSheets() {
    if (form.processing) return;
    sheet.value = null;
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        closeSheets();
        okBooking.value = null;
    }
}
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
watch(sheet, (s) => s && nextTick(() => (document.querySelector('.sheet input') as HTMLInputElement | null)?.focus()));

const canLinkTelegram = computed(() => {
    try {
        return route().has('telegram.link');
    } catch {
        return false;
    }
});
function scrollToSummary() {
    document.getElementById('summary')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function toMap() {
    document.getElementById('map')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function pickStart(t: number) {
    start.value = t;
    notice.value = null;
    lostStart.value = null;
}
const nextText = computed(() => (nextFree.value ? nextLabel(nextFree.value.date, today.value ?? '', hhmm(nextFree.value.start), true) : ''));
const homeUrl = computed(() => route('home', date.value !== today.value ? { date: date.value } : {}));
</script>

<template>
    <PlayerLayout hide-tabbar>
        <Head :title="venue.name" />
        <div class="pg">
            <Link class="backlink" :href="homeUrl"><Icon name="back" size="sm" />{{ $t('player.place.back') }}</Link>

            <!-- header: the place only (no price, no button: booking starts below) -->
            <section class="vhead">
                <span class="kicker">{{ $t(`player.district.${venue.district}`) }} · {{ fields.map((f) => f.format).join(' · ') }}</span>
                <h1>{{ venue.name }}</h1>
                <!-- secondary actions at the top (outline, so the Book button stays the main one) -->
                <div v-if="venue.phone || hasMap" class="vacts">
                    <a v-if="venue.phone" class="btn outline sm" :href="`tel:${venue.phone}`" :title="venue.phoneLabel ?? undefined"><Icon name="phone" size="sm" />{{ $t('player.place.call') }}</a>
                    <a v-if="hasMap" class="btn link sm" href="#map" @click.prevent="toMap"><Icon name="directions" size="sm" />{{ $t('player.my.directions') }}</a>
                </div>
                <div class="vmeta">
                    <span v-if="venue.rating" class="rating"><Icon name="star" size="sm" />{{ venue.rating.toFixed(1) }}</span>
                    <span v-if="venue.reviews">{{ $t('player.reviews', { n: String(venue.reviews) }) }}</span>
                    <span v-if="venue.address"><Icon name="pin" size="sm" />{{ venue.address }}</span>
                    <span v-if="hoursToday"><Icon name="clock" size="sm" />{{ $t('player.place.open', { a: hhmm(hoursToday[0]), b: hhmm(hoursToday[1]) }) }}</span>
                    <span v-else><Icon name="clock" size="sm" />{{ $t('player.place.closedDay') }}</span>
                </div>
            </section>

            <section class="bookgrid">
                <!-- booking: day → field → duration → start time -->
                <div id="panel" class="card flow">
                    <h2>{{ $t('player.place.bookTitle') }}</h2>

                    <div class="step">
                        <div class="plabel"><span><i class="n">1</i>{{ $t('player.place.date') }}</span></div>
                        <div class="days">
                            <button
                                v-for="d in days"
                                :key="d.date"
                                type="button"
                                :class="['day', { we: d.weekend, td: d.k === 0 }]"
                                :aria-pressed="date === d.date"
                                :aria-label="dateLong(d.date)"
                                @click="pickDay(d.date)"
                            >
                                <small>{{ d.k === 0 ? $t('player.today') : wdName(d.wd) }}</small><b>{{ d.day }}</b>
                            </button>
                        </div>
                    </div>

                    <div class="step">
                        <div class="plabel"><span><i class="n">2</i>{{ $t('player.place.field') }}</span></div>
                        <div class="fcards">
                            <button v-for="t in tiles" :key="t.f.id" type="button" :class="['fcard', `s-${t.st}`]" :aria-pressed="fieldId === t.f.id" @click="pickField(t.f.id)">
                                <span class="fthumb"><CourtSvg :size="t.f.size" :color="pitchColor(t.f.surface)" /></span>
                                <span class="fbody">
                                    <b>{{ cname(t.f) }}</b>
                                    <span class="m">{{ t.f.indoor ? $t('player.indoor') : $t('player.outdoor') }} · {{ $t('player.players', { n: String(t.f.players) }) }}</span>
                                    <span class="fp">{{ $t('player.place.fieldPrice', { p: amd(t.f.price.min) }) }}</span>
                                </span>
                                <span v-if="t.st === 'busy'" class="fbusy">{{ t.line }}</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="field" class="step">
                        <div class="plabel"><span><i class="n">3</i>{{ $t('player.place.dur') }}</span></div>
                        <div class="chips">
                            <button v-for="m in field.durations" :key="m" type="button" class="chip" :aria-pressed="minutes === m" @click="pickDur(m)"><Icon name="clock" size="sm" />{{ dur(m) }}</button>
                        </div>
                    </div>

                    <div v-if="field" class="step">
                        <div class="plabel">
                            <span><i class="n">4</i>{{ $t('player.place.time') }}</span>
                            <span v-if="loading" class="faint" style="text-transform: none; letter-spacing: 0">{{ $t('player.place.loading') }}</span>
                        </div>
                        <div v-if="!free.length" class="full">
                            <span v-if="!hoursToday">{{ $t('player.place.closedDay') }}</span>
                            <span v-else-if="allStarts.length">{{ $t('player.place.fullDay') }}</span>
                            <span v-else>{{ $t('player.place.noFree', { dur: dur(minutes) }) }}</span>
                            <span class="full-acts">
                                <button v-if="nextFree" type="button" class="btn outline sm" @click="goNext">
                                    {{ $t('player.place.nextFree', { t: nextText }) }} <Icon name="arrow" size="sm" />
                                </button>
                                <button v-if="hasTaken" type="button" class="btn link sm" @click="showTaken"><Icon name="bell" size="sm" />{{ $t('player.alert.findBtn') }}</button>
                            </span>
                        </div>
                        <div v-if="showHint" class="newhint" role="status">
                            <Icon name="bell" size="sm" /><span>{{ $t('player.alert.newHint') }}</span>
                            <button type="button" class="x" :aria-label="$t('player.close')" @click="seeHint"><Icon name="x" size="sm" /></button>
                        </div>
                        <div v-if="allStarts.length" :class="['slots', { dim: loading, pulse }]">
                            <template v-for="s in allStarts" :key="s.t">
                                <!-- taken: not bookable; a tap offers "notify me if it frees up" -->
                                <button
                                    v-if="s.taken"
                                    type="button"
                                    :class="['tslot taken', { watched: watchedAlert(s.t) }]"
                                    aria-disabled="true"
                                    aria-haspopup="dialog"
                                    :title="watchedAlert(s.t) ? $t('player.alert.watching') : $t('player.place.taken')"
                                    :aria-label="`${hhmm(s.t)} · ${$t('player.place.taken')}${watchedAlert(s.t) ? ' · ' + $t('player.alert.watching') : ''}`"
                                    @click="openTaken(s.t)"
                                >
                                    <span class="tt">{{ hhmm(s.t) }}</span><Icon name="bell" size="sm" class="bell" />
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    :class="['tslot', { prime: twoRates && isPrime(field, s.t), on: start != null && same(s.t, start) }]"
                                    :aria-pressed="start != null && same(s.t, start)"
                                    @click="pickStart(s.t)"
                                >
                                    {{ hhmm(s.t) }}
                                </button>
                            </template>
                        </div>
                        <div v-if="allStarts.length" class="tgl">
                            <template v-if="twoRates">
                                <span><i class="lg free" />{{ $t('player.place.stFree') }} · {{ amd(field.price.min) }} {{ $t('player.perHour') }}</span>
                                <span><i class="lg free pk" />{{ $t('player.place.primeTag') }} · {{ amd(field.price.max) }} {{ $t('player.perHour') }}</span>
                            </template>
                            <span v-else-if="free.length"><i class="lg free" />{{ $t('player.place.stFree') }} · {{ amd(dayRate) }} {{ $t('player.perHour') }}</span>
                        </div>
                        <p v-if="hasTaken" class="bell-hint"><Icon name="bell" size="sm" />{{ $t('player.alert.legend') }}</p>
                    </div>
                </div>

                <!-- summary: what you book and the total, then one clear button -->
                <aside id="summary" class="card sumcard">
                    <div v-if="notice" class="err-box warn">
                        <Icon name="clock" size="sm" />
                        <span class="eb-main">
                            {{ notice }}
                            <button v-if="lostStart != null" type="button" class="eb-act" :disabled="alertBusy" @click="watchLost">
                                <Icon name="bell" size="sm" />{{ $t('player.alert.btnAt', { t: hhmm(lostStart) }) }}
                            </button>
                        </span>
                    </div>
                    <div v-if="(form.errors as Record<string, string>).booking && lostStart == null" class="err-box" role="alert"><Icon name="x" size="sm" /><span>{{ (form.errors as Record<string, string>).booking }}</span></div>

                    <div v-if="!summary" class="empty-sum"><Icon name="calendar" size="lg" /><span>{{ $t('player.place.pick') }}</span></div>
                    <template v-else>
                        <div class="sumhead">
                            <div class="thumb"><CourtSvg :size="summary.f.size" :color="pitchColor(summary.f.surface)" /></div>
                            <div style="min-width: 0">
                                <b class="sumtitle">{{ cname(summary.f) }}</b>
                                <div class="muted sumwhen">{{ dateLong(date) }}</div>
                                <div class="sumtime">{{ range(start!, minutes) }} <span class="faint">· {{ dur(minutes) }}</span></div>
                            </div>
                        </div>
                        <div class="totalrow">
                            <span>{{ $t('player.place.total') }}</span>
                            <b>{{ amd(summary.total) }}</b>
                        </div>
                        <div class="rate" :class="{ prime: summary.primeMin }">
                            <template v-if="summary.primeMin && summary.dayMin">{{ $t('player.place.mixed', { a: dur(summary.dayMin), b: dur(summary.primeMin) }) }}</template>
                            <template v-else-if="summary.primeMin">{{ $t('player.place.primeRate', { p: amd(summary.f.price.max) }) }}</template>
                            <template v-else>{{ $t('player.place.dayRate', { p: amd(summary.f.price.min) }) }}</template>
                        </div>

                        <button v-if="!showNote" type="button" class="addnote" @click="showNote = true"><Icon name="plus" size="sm" />{{ $t('player.place.addNote') }}</button>
                        <div v-else class="opt">
                            <div class="field">
                                <label for="bkTeam">{{ $t('player.place.team') }}</label>
                                <input id="bkTeam" v-model="form.team_name" class="input" maxlength="40" :placeholder="$t('player.place.teamPh')" />
                            </div>
                            <div class="field">
                                <label for="bkNote">{{ $t('player.place.note') }}</label>
                                <textarea id="bkNote" v-model="form.note" class="input" rows="2" maxlength="200" :placeholder="$t('player.place.notePh')" />
                            </div>
                        </div>

                        <p v-if="blocked" class="why blocked">
                            <Icon name="clock" size="sm" /><span>{{ blocked }} <Link :href="route('me.bookings')">{{ $t('player.place.blockedLink') }}</Link></span>
                        </p>
                        <!-- on phones the sticky bar has the Book button, so this one is hidden there -->
                        <button type="button" class="btn block bookbtn" :disabled="form.processing || !!blocked" @click="book">{{ $t('player.place.bookBtn') }}</button>
                        <ul class="perks">
                            <li><Icon name="wallet" size="sm" />{{ $t('player.place.lPay') }}</li>
                            <li><Icon name="check" size="sm" />{{ $t('player.place.lFree', { h: String(venue.rules.freeCancelHours) }) }}</li>
                            <li><Icon name="send" size="sm" />{{ $t('player.place.lTg') }}</li>
                        </ul>
                        <p v-if="user && user.name" class="hint as"><Icon name="user" size="sm" />{{ $t('player.place.as', { name: user.name, phone: user.phone }) }}</p>
                    </template>
                </aside>
            </section>

            <!-- phones: the choice and the button stay at the bottom of the screen -->
            <div v-if="summary && !okBooking && !sheet" :class="['bookbar', { haswhy: blocked || bookError }]">
                <!-- the reason a request can't be sent, or why it failed: right here, not in the card further down -->
                <p v-if="blocked || bookError" class="bb-why" role="alert">
                    <Icon :name="blocked ? 'clock' : 'x'" size="sm" /><span>{{ blocked || bookError }}</span>
                    <Link v-if="blocked" :href="route('me.bookings')">{{ $t('player.place.blockedLink') }}</Link>
                </p>
                <button type="button" class="bbinfo" @click="scrollToSummary">
                    <b>{{ range(start!, minutes) }} · {{ amd(summary.total) }}</b>
                    <small>{{ dateRel(date, today) }} · {{ cname(summary.f) }}</small>
                </button>
                <button type="button" class="btn" :disabled="form.processing || !!blocked" @click="book">{{ $t('player.place.bookBtn') }}</button>
            </div>

            <!-- about the place: one compact list -->
            <section class="section about">
                <h2 class="infoh">{{ $t('player.place.tabInfo') }}</h2>
                <p v-if="venue.description" class="desc">{{ venue.description }}</p>
                <dl class="ilist">
                    <div>
                        <dt><Icon name="clock" size="sm" />{{ $t('player.place.infoHours') }}</dt>
                        <dd>
                            <span v-for="(h, i) in weekHours" :key="i" class="hrow"><span class="muted">{{ h.label }}</span> {{ h.text }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt><Icon name="wallet" size="sm" />{{ $t('player.place.infoPrices') }}</dt>
                        <dd>
                            <span v-for="f in fields" :key="f.id" class="hrow">
                                <span class="fname"><i :class="['field-dot', f.size]" />{{ f.format }}</span>
                                {{ f.price.min === f.price.max ? amd(f.price.min) : `${amd(f.price.min)} – ${amd(f.price.max)}` }} <span class="muted">{{ $t('player.perHour') }}</span>
                            </span>
                            <span class="muted small">{{ $t('player.place.primeTag') }}: {{ $t('player.place.primeWhen') }}</span>
                        </dd>
                    </div>
                    <div v-if="venue.address || hasMap" id="map">
                        <dt><Icon name="pin" size="sm" />{{ $t('player.place.infoAddr') }}</dt>
                        <dd>
                            <span v-if="venue.address">{{ venue.address }}, {{ $t(`player.district.${venue.district}`) }}</span>
                            <!-- Google / Yandex map with its own "Directions" button -->
                            <PlaceMap v-if="hasMap" class="pmap" :lat="venue.lat!" :lng="venue.lng!" :name="venue.name" :height="mapHeight" />
                            <span v-if="!hasMap && dirs.google" class="acts">
                                <a class="btn outline sm" :href="dirs.google" target="_blank" rel="noopener"><Icon name="directions" size="sm" />{{ $t('player.place.google') }}</a>
                            </span>
                        </dd>
                    </div>
                    <div v-if="venue.amenities.length">
                        <dt><Icon name="check" size="sm" />{{ $t('player.place.infoAmen') }}</dt>
                        <dd class="amen">
                            <span v-for="a in venue.amenities" :key="a"><Icon :name="AMEN_ICON[a] || 'check'" size="sm" />{{ $t(`player.amenity.${a}`) }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt><Icon name="ticket" size="sm" />{{ $t('player.place.infoRules') }}</dt>
                        <dd class="muted">{{ $t('player.place.r1') }} {{ $t('player.place.r3', { m: String(venue.rules.minNoticeMin) }) }}</dd>
                    </div>
                </dl>
            </section>
        </div>

        <!-- guest: log in with Telegram (free, confirms the phone), or with email on the login page -->
        <div v-if="sheet" class="scrim" @click.self="closeSheets">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="vt">
                <template v-if="sheet === 'login'">
                    <div class="row" style="margin-bottom: 6px">
                        <h3 id="vt">{{ $t('player.verify.title') }}</h3>
                        <button type="button" class="x" :aria-label="$t('player.close')" @click="closeSheets"><Icon name="x" /></button>
                    </div>
                    <p class="muted" style="margin: 0 0 18px; font-size: 14px">{{ $t('auth.sub2') }}</p>
                    <TelegramLogin context="booking" @done="onLoggedIn" />
                    <div v-if="form.processing" class="muted" style="text-align: center; font-size: 13px; margin-top: 10px">{{ $t('player.verify.sending') }}</div>
                    <Link :href="loginUrl" class="email-alt">{{ $t('auth.withEmail') }}</Link>
                </template>
                <template v-else-if="sheet === 'taken' && takenAt != null && field">
                    <div class="row" style="margin-bottom: 6px">
                        <h3 id="vt">{{ $t('player.alert.title', { t: hhmm(takenAt) }) }}</h3>
                        <button type="button" class="x" :aria-label="$t('player.close')" @click="closeSheets"><Icon name="x" /></button>
                    </div>
                    <p class="muted" style="margin: 0 0 14px; font-size: 14px">
                        {{ dateLong(date) }} · {{ cname(field) }} · {{ range(takenAt, minutes) }}
                    </p>
                    <div v-if="alertError" class="err-box" role="alert" style="margin-bottom: 12px"><Icon name="x" size="sm" /><span>{{ alertError }}</span></div>
                    <template v-if="watchedAlert(takenAt)">
                        <div class="watching"><Icon name="bell" size="sm" />{{ $t('player.alert.watching') }}</div>
                        <button type="button" class="btn outline block" :disabled="alertBusy" @click="unwatchTaken">{{ $t('player.alert.stop') }}</button>
                    </template>
                    <template v-else>
                        <p style="margin: 0 0 14px; font-size: 14px">{{ $t('player.alert.sub') }}</p>
                        <button type="button" class="btn block" :disabled="alertBusy" @click="watchTaken"><Icon name="bell" size="sm" />{{ $t('player.alert.btn') }}</button>
                    </template>
                </template>
                <template v-else>
                    <form @submit.prevent="submit">
                        <div class="row" style="margin-bottom: 6px">
                            <h3 id="vt">{{ $t('player.verify.nameOnly') }}</h3>
                            <button type="button" class="x" :aria-label="$t('player.close')" @click="closeSheets"><Icon name="x" /></button>
                        </div>
                        <div class="field" style="margin: 12px 0 16px">
                            <label for="vn2">{{ $t('player.verify.name') }}</label>
                            <input id="vn2" v-model="form.name" class="input" autocomplete="name" maxlength="60" :placeholder="$t('player.verify.namePh')" />
                            <div v-if="form.errors.name" class="err">{{ form.errors.name }}</div>
                        </div>
                        <button class="btn block" :disabled="form.processing || form.name.trim().length < 2"><Icon name="send" size="sm" />{{ $t('player.verify.go') }}</button>
                    </form>
                </template>
            </div>
        </div>

        <!-- request sent -->
        <div v-if="okBooking" class="scrim" @click.self="okBooking = null">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="okT">
                <div class="row"><button type="button" class="x" :aria-label="$t('player.close')" @click="okBooking = null"><Icon name="x" /></button></div>
                <div class="okhead">
                    <div class="okmark"><Icon name="send" /></div>
                    <h3 id="okT">{{ $t('player.ok.title') }}</h3>
                    <div class="muted" style="font-size: 14px; margin-top: 4px">{{ $t('player.ok.sub') }}</div>
                </div>
                <div class="okcode"><small>{{ $t('player.ok.code') }}</small>{{ okBooking.code }}</div>
                <dl class="oklist">
                    <dt>{{ $t('player.ok.status') }}</dt>
                    <dd><StatusPill :status="okBooking.status" /></dd>
                    <dt>{{ $t('player.ok.venue') }}</dt>
                    <dd>{{ okBooking.venue.name }}</dd>
                    <dt>{{ $t('player.ok.field') }}</dt>
                    <dd>{{ $t(`player.size.${okBooking.field.size}`) }} {{ okBooking.field.format }}</dd>
                    <dt>{{ $t('player.ok.when') }}</dt>
                    <dd>{{ dateLong(okBooking.date) }} · {{ okBooking.start }}–{{ okBooking.end }}</dd>
                    <template v-if="okBooking.venue.address">
                        <dt>{{ $t('player.ok.address') }}</dt>
                        <dd>{{ okBooking.venue.address }}</dd>
                    </template>
                    <template v-if="okBooking.teamName">
                        <dt>{{ $t('player.ok.team') }}</dt>
                        <dd>{{ okBooking.teamName }}</dd>
                    </template>
                    <dt>{{ $t('player.ok.price') }}</dt>
                    <dd>{{ amd(okBooking.price) }}</dd>
                </dl>
                <div v-if="user && !user.telegram && canLinkTelegram" class="tg-note" style="margin: 0 0 12px">
                    <Icon name="send" size="sm" /><span style="flex: 1">{{ $t('player.ok.tgHint') }}</span>
                </div>
                <div v-if="user && !user.telegram && canLinkTelegram" class="tg-box">
                    <TelegramConnect compact />
                </div>
                <!-- the team gets the news and the reminders too -->
                <div class="okteam">
                    <Icon name="users" />
                    <div>
                        <b>{{ $t('player.team.okTitle') }}</b>
                        <p class="muted">{{ $t('player.team.okSub') }}</p>
                    </div>
                </div>
                <div class="okacts">
                    <Link class="btn block" :href="route('me.bookings', { team: okBooking.id })"><Icon name="users" size="sm" />{{ $t('player.team.add') }}</Link>
                    <Link class="btn ghost block" :href="route('me.bookings')"><Icon name="ticket" size="sm" />{{ $t('player.ok.my') }}</Link>
                </div>
            </div>
        </div>
    </PlayerLayout>
</template>

<style scoped>
.pg { overflow-x: clip; }
.okteam { display: flex; gap: 12px; align-items: flex-start; background: var(--surface-2); border-radius: 14px; padding: 12px 14px; margin: 0 0 12px; }
.okteam > .ico { color: var(--accent); margin-top: 2px; flex: none; }
.okteam b { font-size: 14.5px; }
.okteam p { margin: 2px 0 0; font-size: 13px; }
.backlink { display: inline-flex; align-items: center; gap: 8px; color: var(--muted); font-weight: 700; font-size: 14px; margin: 20px 0 14px; }
.backlink:hover { color: var(--accent); }

/* header */
.vhead { margin-bottom: 22px; }
.vhead h1 { font-size: clamp(28px, 4.2vw, 46px); margin: 6px 0 10px; overflow-wrap: anywhere; }
.vmeta { display: flex; flex-wrap: wrap; gap: 8px 18px; color: var(--muted); font-size: 14px; align-items: center; }
.vmeta span { display: inline-flex; align-items: center; gap: 6px; }
.vmeta .rating { font-size: 15px; }
.vacts { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: -2px 0 12px; }
#map { scroll-margin-top: 90px; }

/* booking flow + summary */
.bookgrid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 18px; align-items: start; }
.flow { padding: 20px; display: flex; flex-direction: column; gap: 22px; min-width: 0; }
.flow h2 { margin: 0; font-size: 22px; }
.plabel { font-size: 13.5px; font-weight: 700; color: var(--muted); margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.plabel > span:first-child { display: inline-flex; align-items: center; gap: 8px; }
.plabel .n { font-style: normal; width: 20px; height: 20px; border-radius: 50%; background: var(--surface-3); color: var(--accent); display: grid; place-items: center; font-family: var(--mono); font-size: 11px; letter-spacing: 0; }
.day.td { padding: 7px 8px; }
.day small { display: block; overflow: hidden; text-overflow: ellipsis; padding: 0 3px; }
.day.we:not([aria-pressed="true"]) small { color: var(--warn); }
.fcards { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.fcard { position: relative; display: flex; gap: 12px; align-items: center; text-align: left; padding: 10px; border: 1px solid var(--line-2); border-radius: 16px; background: var(--surface-2); color: var(--ink); min-width: 0; transition: border-color .15s, box-shadow .2s, opacity .2s; }
.fcard:hover { border-color: rgba(220, 255, 220, .3); }
.fcard[aria-pressed="true"] { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); background: rgba(200, 255, 61, .05); }
.fcard.s-busy { opacity: .6; }
.fthumb { width: 72px; flex: none; border-radius: 8px; overflow: hidden; }
.fthumb svg { width: 100%; display: block; }
.fbody { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.fbody b { font-size: 15px; }
.fbody .m { font-size: 12.5px; color: var(--muted); }
.fbody .fp { font-size: 13px; font-weight: 800; color: var(--ink); margin-top: 2px; }
.fbusy { position: absolute; right: 10px; top: 8px; font-size: 11px; font-weight: 800; color: var(--muted); }
.slots { display: flex; gap: 6px; flex-wrap: wrap; transition: opacity .15s; }
.slots.dim { opacity: .5; }
.tslot { font-family: var(--mono); font-size: 14px; padding: 8px 0; width: 74px; text-align: center; border-radius: 9px; background: var(--free-soft); color: var(--free); border: 1px solid rgba(94, 240, 168, .22); }
.tslot:hover { background: rgba(94, 240, 168, .22); }
/* prime price: the same free chip with a small dot (not a different color) */
.tslot { position: relative; }
.tslot.prime::after { content: ''; position: absolute; top: 5px; right: 6px; width: 5px; height: 5px; border-radius: 50%; background: var(--warn); }
.tslot.prime.on::after { background: var(--on-accent); }
.tslot.on { background: var(--accent); color: var(--on-accent); border-color: var(--accent); }
.tslot.taken { background: transparent; color: var(--faint); border: 1px dashed var(--line-2); cursor: pointer; }
.tslot.taken .tt { text-decoration: line-through; }
.tslot.taken:hover { background: transparent; border-color: var(--muted); color: var(--muted); }
.tslot.taken.watched { color: var(--muted); border-style: solid; }
.tslot .bell { width: 11px; height: 11px; margin-left: 4px; vertical-align: -1px; color: var(--faint); }
.tslot.taken:hover .bell { color: var(--muted); }
.tslot.taken.watched .bell { color: var(--accent); }
.slots.pulse .tslot.taken { animation: bb-pulse .8s ease-in-out 2; }
@keyframes bb-pulse { 50% { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(200, 255, 61, .18); } }
@media (prefers-reduced-motion: reduce) { .slots.pulse .tslot.taken { animation: none; border-color: var(--accent); } }
.bell-hint { display: flex; gap: 6px; align-items: center; margin: 8px 0 0; font-size: 12.5px; color: var(--muted); }
.full-acts { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.newhint { display: flex; gap: 8px; align-items: center; padding: 7px 6px 7px 12px; margin-bottom: 10px; border-radius: 12px; background: rgba(200, 255, 61, .08); border: 1px solid rgba(200, 255, 61, .25); color: var(--ink); font-size: 13px; font-weight: 700; }
.newhint > .ico { color: var(--accent); }
.newhint span { flex: 1; }
.newhint .x { flex: none; width: 26px; height: 26px; border: 0; border-radius: 8px; background: none; color: var(--muted); display: grid; place-items: center; }
.eb-main { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
.eb-act { display: inline-flex; gap: 6px; align-items: center; border: 1px solid currentColor; background: none; color: inherit; border-radius: 999px; padding: 5px 12px; font-weight: 800; font-size: 13px; }
.watching { display: flex; gap: 8px; align-items: center; padding: 10px 12px; border-radius: 12px; background: var(--st-pending-soft); color: var(--st-pending); font-weight: 700; font-size: 14px; margin-bottom: 12px; }
.full { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; padding: 10px 12px; margin-bottom: 10px; border-radius: 12px; background: var(--surface-2); font-size: 14px; font-weight: 700; }
.tgl { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12.5px; font-weight: 700; color: var(--muted); margin-top: 10px; }
.tgl span { display: inline-flex; gap: 6px; align-items: center; }
.tgl .lg { width: 12px; height: 12px; border-radius: 4px; display: inline-block; }
.tgl .free { background: var(--free-soft); box-shadow: inset 0 0 0 1px rgba(94, 240, 168, .4); }
.tgl .lg.pk { position: relative; }
.tgl .lg.pk::after { content: ''; position: absolute; top: 2px; right: 2px; width: 4px; height: 4px; border-radius: 50%; background: var(--warn); }
.err-box { display: flex; gap: 8px; align-items: flex-start; padding: 10px 12px; border-radius: 12px; background: var(--hot-soft); color: var(--hot); font-size: 13.5px; font-weight: 700; }
.err-box.warn { background: var(--warn-soft); color: var(--warn); }
.err-box .ico { margin-top: 2px; }

.sumcard { padding: 18px; display: flex; flex-direction: column; gap: 14px; position: sticky; top: 84px; min-width: 0; }
.empty-sum { color: var(--muted); font-size: 14px; display: flex; align-items: center; gap: 12px; padding: 8px 0; }
.sumhead { display: flex; gap: 12px; align-items: center; }
.sumhead .thumb { width: 76px; flex: none; border-radius: 8px; overflow: hidden; }
.sumhead .thumb svg { width: 100%; display: block; }
.sumtitle { font-size: 17px; display: block; }
.sumwhen { font-size: 13.5px; }
.sumtime { font-family: var(--mono); font-size: 16px; margin-top: 2px; }
.totalrow { display: flex; justify-content: space-between; align-items: baseline; padding-top: 12px; border-top: 1px solid var(--line); }
.totalrow span { font-size: 14px; font-weight: 700; color: var(--muted); }
.totalrow b { font-family: var(--display); font-size: 28px; font-weight: 600; letter-spacing: -.02em; }
.rate { font-size: 12.5px; color: var(--muted); margin-top: -8px; }
.rate.prime { color: var(--warn); }
.addnote { align-self: flex-start; background: none; border: 0; padding: 0; color: var(--muted); font-size: 13px; font-weight: 700; display: inline-flex; gap: 6px; align-items: center; }
.addnote:hover { color: var(--ink); }
.opt { display: grid; gap: 10px; }
.opt textarea.input { resize: vertical; min-height: 58px; }
.bookbtn { font-size: 16px; padding: 14px 20px; }
.hint { margin: -4px 0 0; font-size: 12.5px; color: var(--muted); line-height: 1.45; }
.perks { list-style: none; margin: -4px 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 12.5px; color: var(--muted); }
.perks li { display: inline-flex; gap: 5px; align-items: center; }
.perks .ico { color: var(--free); }
.hint.as { display: flex; gap: 6px; align-items: center; margin-top: -8px; }

/* bottom bar on phones */
.bookbar { display: none; }
.why.blocked { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; color: var(--st-pending); margin: 4px 0 8px; }
.why.blocked .ico { margin-top: 2px; flex: none; }
.why.blocked a, .bb-why a { color: var(--ink); text-decoration: underline; font-weight: 700; }
.bb-why { display: none; }
@media (max-width: 1040px) {
  .bookgrid { grid-template-columns: 1fr; }
  .sumcard { position: static; }
  .bookbtn { display: none; }
  .bookbar.haswhy { flex-wrap: wrap; }
  .bookbar .bb-why { display: flex; flex-wrap: wrap; flex-basis: 100%; gap: 4px 6px; align-items: flex-start; margin: 0; font-size: 12.5px; font-weight: 700; color: var(--st-pending); }
  .bookbar .bb-why span { flex: 1; min-width: 0; }
  .bookbar .bb-why .ico { margin-top: 2px; flex: none; }
  .bookbar .bb-why a { flex-basis: 100%; padding-left: 22px; }
  .bookbar { display: flex; position: fixed; left: 10px; right: 10px; bottom: calc(10px + env(safe-area-inset-bottom)); z-index: 45; align-items: center; gap: 10px; padding: 8px 8px 8px 14px; border-radius: 18px; background: rgba(27, 33, 28, .96); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--line-2); box-shadow: var(--shadow); }
  .bbinfo { flex: 1; min-width: 0; text-align: left; background: none; border: 0; padding: 0; color: var(--ink); display: flex; flex-direction: column; }
  .bbinfo b { font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .bbinfo small { font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .pg { padding-bottom: 90px; }
}
@media (max-width: 600px) {
  .flow, .sumcard { padding: 14px; }
  .fcards { grid-template-columns: 1fr; }
}

/* about the place: one compact list */
.infoh { font-size: 22px; margin: 0 0 10px; }
.desc { color: var(--muted); max-width: 760px; margin: 0 0 12px; font-size: 15px; }
.ilist { margin: 0; border-top: 1px solid var(--line); max-width: 860px; }
.ilist > div { display: grid; grid-template-columns: 170px minmax(0, 1fr); gap: 4px 16px; padding: 12px 0; border-bottom: 1px solid var(--line); }
.ilist dt { display: flex; gap: 8px; align-items: center; color: var(--muted); font-weight: 700; font-size: 14px; align-self: start; padding-top: 1px; }
.ilist dd { margin: 0; display: flex; flex-direction: column; gap: 4px; font-size: 14.5px; min-width: 0; }
.ilist .hrow { display: flex; gap: 8px; flex-wrap: wrap; }
.ilist .fname { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; min-width: 56px; }
.ilist .small { font-size: 12.5px; }
.ilist .acts { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
.ilist .pmap { margin-top: 8px; max-width: 640px; }
.ilist .amen { flex-direction: row; flex-wrap: wrap; gap: 6px 16px; }
.ilist .amen span { display: inline-flex; gap: 6px; align-items: center; }
.ilist .amen .ico { color: var(--muted); }
@media (max-width: 600px) { .ilist > div { grid-template-columns: 1fr; } }

/* sheets */
.err { color: var(--hot); font-size: 13px; margin-top: 8px; }
.tg-note { display: flex; gap: 10px; align-items: center; margin: 14px 0 18px; padding: 10px 12px; border-radius: 12px; background: var(--blue-soft); color: var(--blue); font-size: 13px; font-weight: 700; }
.okhead { text-align: center; padding: 6px 0 4px; }
.okmark { width: 62px; height: 62px; border-radius: 50%; margin: 0 auto 12px; background: var(--grad); color: var(--on-accent); display: grid; place-items: center; box-shadow: 0 10px 30px -8px rgba(200, 255, 61, .6); }
.okmark .ico { width: 30px; height: 30px; stroke-width: 3; }
.email-alt { display: block; text-align: center; margin-top: 14px; color: var(--muted); font-size: 13px; font-weight: 700; }
.email-alt:hover { color: var(--ink); }
.okcode { font-family: var(--mono); font-size: 26px; letter-spacing: .12em; background: var(--bg-2); border: 1px dashed var(--line-2); border-radius: 14px; padding: 10px; text-align: center; margin: 14px 0; }
.okcode small { display: block; font-family: var(--body); font-size: 12.5px; letter-spacing: 0; color: var(--muted); font-weight: 700; }
.oklist { display: grid; grid-template-columns: auto 1fr; gap: 8px 14px; font-size: 14px; margin: 0 0 16px; }
.oklist dt { color: var(--muted); font-weight: 700; }
.oklist dd { margin: 0; font-weight: 700; text-align: right; }
.okacts { display: grid; grid-template-columns: 1fr; gap: 8px; }
.tg-box { margin: -4px 0 12px; }
.tg-box :deep(.tg-connect) { padding: 0 !important; }
.tg-box :deep(.btn) { width: 100%; background: var(--blue); color: #04121b; }
</style>
