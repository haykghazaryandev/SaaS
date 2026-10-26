<script setup lang="ts">
import PlaceMap from '@/components/PlaceMap.vue';
import TelegramLogin from '@/components/TelegramLogin.vue';
import Icon from '@/components/Icon.vue';
import CourtSvg from '@/components/player/CourtSvg.vue';
import StatusPill from '@/components/player/StatusPill.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import { amd, hhmm, useShared } from '@/composables/useShared';
import { courtName, courtShort, dateLong, dateRel, directionLinks, dur, nextLabel, pitchColor, toHours, wdName } from '@/composables/usePlayer';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import { useSportTheme } from '@/lib/sport';
import { useLive } from '@/lib/echo';
import type { DayInfo, FieldInfo, MyTime, PlayerBooking, SlotsPayload } from '@/types/player';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { trans } from '@/lib/i18n';
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
    /** the sport of the place's courts (ping-pong: tables, its own colours) */
    sport?: string;
    /** what the place offers (admin → place → features) */
    features?: string[];
    /** the club parts: my card here (null: no cards; false: not made yet), trainings, shop, open tournaments */
    club?: {
        card: { balance: number; discount: number } | false | null;
        products: { id: number; name: string; description: string | null; price: number; final: number; discount_pct: number; in_stock: boolean; photo: string | null }[];
        tournaments: { code: string; title: string; when: string; from: number }[];
    };
}>();
// my discount at this place (my card): already in the price the place gets
const myDiscount = computed(() => (props.club?.card ? props.club.card.discount : 0));
const getCard = () => router.post(route('places.card', props.venue.slug));
const has = (f: string) => !props.features || props.features.includes(f);
const isTables = computed(() => props.sport === 'pingpong');
useSportTheme(() => props.sport);
// one kind of court only (all the club's tables): no court step, the numbers of the next steps move up
const oneKind = computed(() => props.fields.length <= 1);
const stepNo = (n: number) => (oneKind.value && n > 2 ? n - 1 : n);
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
// play or training (a training is booked like a table, at the place's training price per hour)
const purpose = ref<'play' | 'training'>('play');
const trainingPrice = computed(() => field.value?.trainingPrice ?? null);
watch(trainingPrice, (p) => p == null && (purpose.value = 'play'));
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

// prices: every quarter hour is charged by its own hourly rate (same rule as PriceCalculator); rates come per half hour
function rateAt(f: FieldInfo, h: number): number {
    const half = Math.floor(h * 2 + 1e-6) / 2;
    const r = slots.value.fields.find((x) => x.id === f.id)?.rates.find(([t]) => same(t, half));
    return r ? r[1] : f.price.max;
}
const isPrime = (f: FieldInfo, h: number) => rateAt(f, h) > f.price.min;
function priceOf(f: FieldInfo, s: number, m: number) {
    let total = 0;
    let dayMin = 0;
    let primeMin = 0;
    for (let k = 0; k < m; k += 15) {
        const h = s + k / 60;
        total += rateAt(f, h) / 4;
        if (isPrime(f, h)) primeMin += 15;
        else dayMin += 15;
    }
    return { total: Math.round(total), dayMin, primeMin };
}
const range = (s: number, m: number) => `${hhmm(s)}–${hhmm(s + m / 60)}`;
const cname = (f: FieldInfo) => courtName(f);

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
        if (data.limit !== undefined) limit.value = data.limit;
        revalidate();
        if (quiet && before != null && (start.value == null || !same(before, start.value))) {
            // the picked time was just taken: offer "notify me if it frees up" for exactly that time
            const nowTaken = (slotRow.value?.taken?.[String(minutes.value)] ?? []).some((t) => same(t, before));
            lostStart.value = nowTaken ? before : null;
            notice.value = trans(nowTaken ? 'player.alert.just_taken' : 'player.place.refreshed');
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
    load(true);   // the page may come from a cache or "back": check times, "yours" and the limit now
    timer = window.setInterval(() => document.visibilityState === 'visible' && load(true), 60_000);
    document.addEventListener('visibilitychange', onVisible);
    if (props.booked) okBooking.value = props.booked;
});
onBeforeUnmount(() => {
    clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisible);
    clearTimeout(liveT);
});
// live: a time of this place was booked or freed on the day shown → refresh the times at once
let liveT: number | undefined;
useLive(
    () => `place.${props.venue.id}`,
    'schedule.changed',
    (d) => {
        if (!(d.dates as string[] | undefined)?.includes(date.value)) return;
        clearTimeout(liveT);
        liveT = window.setTimeout(() => load(true), 300);
    },
);
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
            if (!list.length) line = trans('player.place.slot_none');
        } else {
            const ok = list.some((t) => same(t, start.value!));
            st = ok ? (f.id === fieldId.value ? 'sel' : 'free') : 'busy';
            if (ok) line = `${amd(priceOf(f, start.value, d).total)} · ${dur(d)}`;
            else {
                const n = nearest(list, start.value);
                line = n != null ? trans('player.place.slot_next', { t: hhmm(n) }) : trans('player.place.slot_none');
            }
        }
        return { f, st, line };
    }),
);

// ---------- summary ----------
const summary = computed(() => {
    const f = field.value;
    if (!f || start.value == null) return null;
    const base = priceOf(f, start.value, minutes.value);
    // a training: its own price per hour for the whole time
    const p = purpose.value === 'training' && f.trainingPrice != null ? { ...base, total: Math.round((f.trainingPrice * minutes.value) / 60), dayMin: 0, primeMin: 0 } : base;
    const wd = props.days.find((d) => d.date === date.value);
    const d = myDiscount.value;
    return { f, ...p, total: d ? Math.round((p.total * (100 - d)) / 100) : p.total, full: p.total, discount: d, weekday: wd ? !wd.weekend : true };
});
const hoursToday = computed(() => slots.value.hours);
/** "Every day 08:00–24:00", or runs of days with the same hours: "Mon–Fri 08:00–24:00", "Sat–Sun 09:00–23:00" */
const weekHours = computed(() => {
    const w = props.venue.week;
    const span = (x?: [number, number, number]) => (x ? `${hhmm(x[1])}–${hhmm(x[2])}` : null);
    if (w.length === 7 && w.every((x) => x[1] === w[0][1] && x[2] === w[0][2])) return [{ label: trans('player.place.every_day'), text: span(w[0])! }];
    const order = [1, 2, 3, 4, 5, 6, 0];
    const runs: { from: number; to: number; text: string | null }[] = [];
    for (const d of order) {
        const text = span(w.find((y) => y[0] === d));
        const last = runs[runs.length - 1];
        if (last && last.text === text) last.to = d;
        else runs.push({ from: d, to: d, text });
    }
    return runs.map((r) => ({ label: r.from === r.to ? wdName(r.from) : `${wdName(r.from)}–${wdName(r.to)}`, text: r.text ?? trans('common.closed') }));
});
const dirs = computed(() => directionLinks({ ...props.venue }));
const hasMap = computed(() => props.venue.lat != null && props.venue.lng != null);
const mapHeight = ref(260);
onMounted(() => {
    if (window.matchMedia('(max-width: 600px)').matches) mapHeight.value = 220;
});
const AMEN_ICON: Record<string, string> = { parking: 'car', showers: 'shower', lockers: 'locker', cafe: 'coffee', lights: 'bolt', bibs: 'ball', stands: 'users' };

// ---------- request ----------
const form = useForm({ field_id: 0, date: '', start: '', minutes: 60, team_name: '', note: '', name: '', purpose: 'play' });
// guests confirm who they are with Telegram (free) right in the sheet, then the request is sent
const sheet = ref<null | 'login' | 'name' | 'taken' | 'mine'>(null);
const okBooking = ref<PlayerBooking | null>(null);
const loginUrl = computed(() => route('login', { redirect: window.location.pathname + window.location.search }));

const bookError = computed(() => (lostStart.value == null ? (form.errors as Record<string, string>).booking ?? null : null));
// a new request can't be sent: say so before "Book" (G2), with the way out, instead of failing after the tap
// the latest limit: from the page, then from every refresh of the times (withdrawn elsewhere → gone at once)
const limit = ref(props.limit);
watch(() => props.limit, (l) => (limit.value = l));
const blocked = computed<string | null>(() => {
    const l = limit.value;
    if (!l) return null;
    if (l.bannedUntil) return trans('player.place.blocked_ban', { until: l.bannedUntil });
    if (l.pending >= l.maxPending) return trans('player.place.blocked_pending', { n: String(l.pending) });
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
    form.purpose = purpose.value;
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
const showHint = computed(() => !!user.value && hasTaken.value && !hintSeen.value && has('alerts'));
const afterLogin = ref<'book' | 'alert'>('book');
const alertBusy = ref(false);
const alertError = ref<string | null>(null);
const watchedAlert = (t: number) =>
    field.value ? (props.alerts ?? []).find((a) => a.field === field.value!.id && a.date === date.value && same(toHours(a.start), t)) : undefined;
// ---------- my own request / booking among the times ----------
/** the player's own booking covering this start time on the picked field */
// my requests / bookings on this day and court kind (shown in their own row above the times)
const myToday = computed(() => (field.value ? (slots.value.mine ?? []).filter((m) => m.field === field.value!.id) : []));
const mineOpen = ref<MyTime | null>(null);
const cancelBusy = ref(false);
function openMine(m: MyTime) {
    mineOpen.value = m;
    sheet.value = 'mine';
}
function cancelMine() {
    const m = mineOpen.value;
    if (!m || !window.confirm(trans(m.status === 'pending' ? 'player.place.mine_withdraw_ask' : 'common.cancel_this_booking'))) return;
    cancelBusy.value = true;
    router.post(route('bookings.cancel', m.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            sheet.value = null;
            load(true);
        },
        onFinish: () => (cancelBusy.value = false),
    });
}
function openTaken(t: number) {
    if (!has('alerts')) return;   // the place has "notify me" switched off
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
            <Link class="backlink" :href="homeUrl"><Icon name="back" size="sm" />{{ $t('common.all_fields') }}</Link>

            <!-- header: the place only (no price, no button: booking starts below) -->
            <section class="vhead">
                <span class="kicker">{{ $t(`common.districts.${venue.district}`) }} · <template v-if="isTables">🏓 {{ $t('player.place.tables_n', { n: String(fields[0]?.count ?? 1) }) }}</template><template v-else>{{ fields.map((f) => courtShort(f)).join(' · ') }}</template></span>
                <h1>{{ venue.name }}</h1>
                <!-- secondary actions at the top (outline, so the Book button stays the main one) -->
                <div v-if="venue.phone || hasMap" class="vacts">
                    <a v-if="venue.phone" class="btn outline sm" :href="`tel:${venue.phone}`" :title="venue.phoneLabel ?? undefined"><Icon name="phone" size="sm" />{{ $t('common.call') }}</a>
                    <a v-if="hasMap" class="btn link sm" href="#map" @click.prevent="toMap"><Icon name="directions" size="sm" />{{ $t('common.directions') }}</a>
                </div>
                <div class="vmeta">
                    <span v-if="venue.rating" class="rating"><Icon name="star" size="sm" />{{ venue.rating.toFixed(1) }}</span>
                    <span v-if="venue.reviews">{{ $t('common.reviews_count', { n: String(venue.reviews) }) }}</span>
                    <span v-if="venue.address"><Icon name="pin" size="sm" />{{ venue.address }}</span>
                    <span v-if="hoursToday"><Icon name="clock" size="sm" />{{ $t('player.place.open_hours', { a: hhmm(hoursToday[0]), b: hhmm(hoursToday[1]) }) }}</span>
                    <span v-else><Icon name="clock" size="sm" />{{ $t('player.place.closed_day') }}</span>
                </div>
            </section>

            <section class="bookgrid">
                <!-- booking: day → field → duration → start time -->
                <div id="panel" class="card flow">
                    <h2>{{ isTables ? $t('player.place.book_a_table') : $t('common.book_a_field') }}</h2>
                    <p v-if="!has('booking')" class="muted">{{ $t('player.place.no_online_booking') }}</p>
                    <!-- play or a training (its own price per hour), only where the place has trainings -->
                    <div v-if="has('booking') && trainingPrice != null" class="seg block purpose" role="group">
                        <button type="button" :aria-pressed="purpose === 'play'" @click="purpose = 'play'"><Icon name="ball" size="sm" />{{ $t('club.play') }}</button>
                        <button type="button" :aria-pressed="purpose === 'training'" data-test="purpose-training" @click="purpose = 'training'">
                            <Icon name="whistle" size="sm" /><span>{{ $t('club.training') }}<small>{{ amd(trainingPrice) }} {{ $t('common.per_hour') }}</small></span>
                        </button>
                    </div>

                    <template v-if="has('booking')">
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
                                <small>{{ d.k === 0 ? $t('common.today') : wdName(d.wd) }}</small><b>{{ d.day }}</b>
                            </button>
                        </div>
                    </div>

                    <div v-if="!oneKind" class="step">
                        <div class="plabel"><span><i class="n">2</i>{{ $t('common.field') }}</span></div>
                        <div class="fcards">
                            <button v-for="t in tiles" :key="t.f.id" type="button" :class="['fcard', `s-${t.st}`]" :aria-pressed="fieldId === t.f.id" @click="pickField(t.f.id)">
                                <span class="fthumb"><CourtSvg :size="t.f.size" :color="pitchColor(t.f.surface)" /></span>
                                <span class="fbody">
                                    <b>{{ cname(t.f) }}</b>
                                    <span class="m"
                                        >{{ t.f.indoor ? $t('player.indoor') : $t('common.outdoor') }} ·
                                        <template v-if="(t.f.count ?? 1) > 1">{{ $t('player.place.courts_n', { n: String(t.f.count) }) }}</template
                                        ><template v-else>{{ $t('common.players_count', { n: String(t.f.players) }) }}</template></span
                                    >
                                    <span class="fp">{{ $t('player.place.field_price', { p: amd(t.f.price.min) }) }}</span>
                                </span>
                                <span v-if="t.st === 'busy'" class="fbusy">{{ t.line }}</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="field" class="step">
                        <div class="plabel"><span><i class="n">{{ stepNo(3) }}</i>{{ $t('common.duration') }}</span></div>
                        <div class="chips">
                            <button v-for="m in field.durations" :key="m" type="button" class="chip" :aria-pressed="minutes === m" @click="pickDur(m)"><Icon name="clock" size="sm" />{{ dur(m) }}</button>
                        </div>
                    </div>

                    <div v-if="field" class="step">
                        <div class="plabel">
                            <span><i class="n">{{ stepNo(4) }}</i>{{ $t('player.place.start_time') }}</span>
                            <span v-if="loading" class="faint" style="text-transform: none; letter-spacing: 0">{{ $t('player.place.loading_times') }}</span>
                        </div>
                        <div v-if="!free.length" class="full">
                            <span v-if="!hoursToday">{{ $t('player.place.closed_day') }}</span>
                            <span v-else-if="allStarts.length">{{ $t('player.place.fully_booked') }}</span>
                            <span v-else>{{ $t('player.place.no_free_times', { dur: dur(minutes) }) }}</span>
                            <span class="full-acts">
                                <button v-if="nextFree" type="button" class="btn outline sm" @click="goNext">
                                    {{ $t('common.next_free_t', { t: nextText }) }} <Icon name="arrow" size="sm" />
                                </button>
                                <button v-if="hasTaken && has('alerts')" type="button" class="btn link sm" @click="showTaken"><Icon name="bell" size="sm" />{{ $t('player.alert.find_button') }}</button>
                            </span>
                        </div>
                        <div v-if="showHint" class="newhint" role="status">
                            <Icon name="bell" size="sm" /><span>{{ $t('player.alert.new_hint') }}</span>
                            <button type="button" class="x" :aria-label="$t('common.close')" @click="seeHint"><Icon name="x" size="sm" /></button>
                        </div>
                        <!-- my own requests / bookings this day here: one chip each with the whole time; a tap shows it (status, code, cancel) -->
                        <div v-if="myToday.length" class="minerow">
                            <span class="faint">{{ $t('player.place.yours') }}</span>
                            <button
                                v-for="m in myToday"
                                :key="m.id"
                                type="button"
                                :class="['tslot mine wide', m.status]"
                                aria-haspopup="dialog"
                                data-test="mine"
                                @click="openMine(m)"
                            >
                                {{ hhmm(m.start) }}–{{ hhmm(m.end) }}
                            </button>
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
                                <span><i class="lg free" />{{ $t('player.place.slot_free') }} · {{ amd(field.price.min) }} {{ $t('common.per_hour') }}</span>
                                <span><i class="lg free pk" />{{ $t('player.place.prime_tag') }} · {{ amd(field.price.max) }} {{ $t('common.per_hour') }}</span>
                            </template>
                            <span v-else-if="free.length"><i class="lg free" />{{ $t('player.place.slot_free') }} · {{ amd(dayRate) }} {{ $t('common.per_hour') }}</span>
                            <span v-if="(slots.mine ?? []).some((m) => m.field === field!.id)"><i class="lg you" />{{ $t('player.place.mine_title') }}</span>
                        </div>
                        <p v-if="hasTaken && has('alerts')" class="bell-hint"><Icon name="bell" size="sm" />{{ $t('player.alert.legend') }}</p>
                    </div>
                    </template>
                </div>

                <!-- summary: what you book and the total, then one clear button -->
                <aside v-if="has('booking')" id="summary" class="card sumcard">
                    <div v-if="notice" class="err-box warn">
                        <Icon name="clock" size="sm" />
                        <span class="eb-main">
                            {{ notice }}
                            <button v-if="lostStart != null" type="button" class="eb-act" :disabled="alertBusy" @click="watchLost">
                                <Icon name="bell" size="sm" />{{ $t('player.alert.button_at', { t: hhmm(lostStart) }) }}
                            </button>
                        </span>
                    </div>
                    <div v-if="(form.errors as Record<string, string>).booking && lostStart == null" class="err-box" role="alert"><Icon name="x" size="sm" /><span>{{ (form.errors as Record<string, string>).booking }}</span></div>

                    <div v-if="!summary" class="empty-sum"><Icon name="calendar" size="lg" /><span>{{ oneKind ? $t('player.place.pick_time') : $t('player.place.pick') }}</span></div>
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
                            <b><s v-if="summary.discount" class="faint" style="font-weight: 400; margin-right: 6px">{{ amd(summary.full) }}</s>{{ amd(summary.total) }}</b>
                        </div>
                        <div v-if="summary.discount" class="rate">{{ $t('club.your_discount', { p: summary.discount }) }}</div>
                        <div class="rate" :class="{ prime: summary.primeMin }">
                            <template v-if="summary.primeMin && summary.dayMin">{{ $t('player.place.price_mixed', { a: dur(summary.dayMin), b: dur(summary.primeMin) }) }}</template>
                            <template v-else-if="summary.primeMin">{{ $t('player.place.prime_rate', { p: amd(summary.f.price.max) }) }}</template>
                            <template v-else>{{ $t('player.place.day_rate', { p: amd(summary.f.price.min) }) }}</template>
                        </div>

                        <button v-if="!showNote" type="button" class="addnote" @click="showNote = true"><Icon name="plus" size="sm" />{{ $t('player.place.add_note') }}</button>
                        <div v-else class="opt">
                            <div class="field">
                                <label for="bkTeam">{{ $t('player.place.team_label') }}</label>
                                <input id="bkTeam" v-model="form.team_name" class="input" maxlength="40" :placeholder="$t('player.place.team_placeholder')" />
                            </div>
                            <div class="field">
                                <label for="bkNote">{{ $t('player.place.note_label') }}</label>
                                <textarea id="bkNote" v-model="form.note" class="input" rows="2" maxlength="200" :placeholder="$t('player.place.note_placeholder')" />
                            </div>
                        </div>

                        <p v-if="blocked" class="why blocked">
                            <Icon name="clock" size="sm" /><span>{{ blocked }} <Link :href="route('me.bookings')">{{ $t('common.my_bookings') }}</Link></span>
                        </p>
                        <!-- on phones the sticky bar has the Book button, so this one is hidden there -->
                        <button type="button" class="btn block bookbtn" :disabled="form.processing || !!blocked" @click="book">{{ $t('player.place.book_button') }}</button>
                        <ul class="perks">
                            <li><Icon name="wallet" size="sm" />{{ $t('player.place.badge_pay') }}</li>
                            <li><Icon name="check" size="sm" />{{ $t('player.place.badge_free_cancel', { h: String(venue.rules.freeCancelHours) }) }}</li>
                            <li><Icon name="send" size="sm" />{{ $t('player.place.badge_telegram') }}</li>
                        </ul>
                        <p v-if="user && user.name" class="hint as"><Icon name="user" size="sm" />{{ $t('player.place.booking_as', { name: user.name, phone: user.phone }) }}</p>
                    </template>
                </aside>
            </section>

            <!-- phones: the choice and the button stay at the bottom of the screen -->
            <div v-if="summary && !okBooking && !sheet" :class="['bookbar', { haswhy: blocked || bookError }]">
                <!-- the reason a request can't be sent, or why it failed: right here, not in the card further down -->
                <p v-if="blocked || bookError" class="bb-why" role="alert">
                    <Icon :name="blocked ? 'clock' : 'x'" size="sm" /><span>{{ blocked || bookError }}</span>
                    <Link v-if="blocked" :href="route('me.bookings')">{{ $t('common.my_bookings') }}</Link>
                </p>
                <button type="button" class="bbinfo" @click="scrollToSummary">
                    <b>{{ range(start!, minutes) }} · {{ amd(summary.total) }}</b>
                    <small>{{ dateRel(date, today) }} · {{ cname(summary.f) }}</small>
                </button>
                <button type="button" class="btn" :disabled="form.processing || !!blocked" @click="book">{{ $t('player.place.book_button') }}</button>
            </div>

            <!-- the club: my card (QR), trainings, shop, tournaments: only what the place switched on -->
            <section v-if="club && (club.card !== null || club.products.length || club.tournaments.length)" class="section clubs">
                <div v-if="club.card !== null" class="card pad mycard">
                    <Icon name="qr" size="lg" />
                    <span class="mc">
                        <b>{{ $t('club.my_card_here') }}</b>
                        <small v-if="club.card">{{ amd(club.card.balance) }}<template v-if="club.card.discount"> · {{ $t('club.your_discount', { p: club.card.discount }) }}</template></small>
                        <small v-else>{{ $t('club.card_pitch') }}</small>
                    </span>
                    <Link v-if="club.card" class="btn sm" :href="route('me.cards', { venue: venue.slug })">{{ $t('club.show_qr') }}</Link>
                    <button v-else-if="user" type="button" class="btn sm" data-test="get-card" @click="getCard">{{ $t('club.get_card') }}</button>
                    <Link v-else class="btn sm" :href="route('login')">{{ $t('club.get_card') }}</Link>
                </div>

                <div v-if="club.tournaments.length" class="card pad">
                    <h2 class="infoh">{{ $t('club.tournaments') }}</h2>
                    <Link v-for="t in club.tournaments" :key="t.code" class="crow" :href="route('tournament.show', t.code)">
                        <Icon name="trophy" size="sm" /><span class="w"><b>{{ t.title }}</b><small>{{ t.when }}</small></span
                        ><span class="pill">{{ t.from ? $t('common.from_lower') + ' ' + amd(t.from) : $t('club.free') }}</span><Icon name="arrow" size="sm" />
                    </Link>
                </div>

                <div v-if="club.products.length" class="card pad">
                    <h2 class="infoh">{{ $t('club.shop') }}</h2>
                    <div class="prods">
                        <div v-for="p in club.products" :key="p.id" :class="['prod', { out: !p.in_stock }]">
                            <div class="img"><img v-if="p.photo" :src="p.photo" alt="" loading="lazy" /><Icon v-else name="cube" /></div>
                            <b>{{ p.name }}</b>
                            <span class="pr"><s v-if="p.discount_pct">{{ amd(p.price) }}</s> {{ amd(p.final) }}</span>
                            <small v-if="!p.in_stock" class="faint">{{ $t('club.out_of_stock') }}</small>
                        </div>
                    </div>
                    <p class="faint" style="font-size: 12.5px; margin: 8px 0 0">{{ $t('club.shop_hint') }}</p>
                </div>
            </section>

            <!-- about the place: one compact list -->
            <section class="section about">
                <h2 class="infoh">{{ $t('player.place.tab_info') }}</h2>
                <p v-if="venue.description" class="desc">{{ venue.description }}</p>
                <dl class="ilist">
                    <div>
                        <dt><Icon name="clock" size="sm" />{{ $t('common.opening_hours') }}</dt>
                        <dd>
                            <span v-for="(h, i) in weekHours" :key="i" class="hrow"><span class="muted">{{ h.label }}</span> {{ h.text }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt><Icon name="wallet" size="sm" />{{ $t('common.prices') }}</dt>
                        <dd>
                            <span v-for="f in fields" :key="f.id" class="hrow">
                                <span class="fname"><i :class="['field-dot', f.size]" />{{ f.format }}</span>
                                {{ f.price.min === f.price.max ? amd(f.price.min) : `${amd(f.price.min)} – ${amd(f.price.max)}` }} <span class="muted">{{ $t('common.per_hour') }}</span>
                            </span>
                            <span class="muted small">{{ $t('player.place.prime_tag') }}: {{ $t('player.place.prime_when') }}</span>
                        </dd>
                    </div>
                    <div v-if="venue.address || hasMap" id="map">
                        <dt><Icon name="pin" size="sm" />{{ $t('common.address') }}</dt>
                        <dd>
                            <span v-if="venue.address">{{ venue.address }}, {{ $t(`common.districts.${venue.district}`) }}</span>
                            <!-- Google / Yandex map with its own "Directions" button -->
                            <PlaceMap v-if="hasMap" class="pmap" :lat="venue.lat!" :lng="venue.lng!" :name="venue.name" :height="mapHeight" />
                            <span v-if="!hasMap && dirs.google" class="acts">
                                <a class="btn outline sm" :href="dirs.google" target="_blank" rel="noopener"><Icon name="directions" size="sm" />{{ $t('player.place.map_google') }}</a>
                            </span>
                        </dd>
                    </div>
                    <div v-if="venue.amenities.length">
                        <dt><Icon name="check" size="sm" />{{ $t('player.place.info_amenities') }}</dt>
                        <dd class="amen">
                            <span v-for="a in venue.amenities" :key="a"><Icon :name="AMEN_ICON[a] || 'check'" size="sm" />{{ $t(`player.amenity.${a}`) }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt><Icon name="ticket" size="sm" />{{ $t('player.place.info_rules') }}</dt>
                        <dd class="muted">{{ $t('player.place.rule_request') }} {{ $t('player.place.rule_notice', { m: String(venue.rules.minNoticeMin) }) }}</dd>
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
                        <button type="button" class="x" :aria-label="$t('common.close')" @click="closeSheets"><Icon name="x" /></button>
                    </div>
                    <p class="muted" style="margin: 0 0 18px; font-size: 14px">{{ $t('auth.sub2') }}</p>
                    <TelegramLogin context="booking" @done="onLoggedIn" />
                    <div v-if="form.processing" class="muted" style="text-align: center; font-size: 13px; margin-top: 10px">{{ $t('player.verify.sending') }}</div>
                    <Link :href="loginUrl" class="email-alt">{{ $t('auth.with_email') }}</Link>
                </template>
                <template v-else-if="sheet === 'mine' && mineOpen && field">
                    <div class="row" style="margin-bottom: 6px">
                        <h3 id="vt">{{ $t(mineOpen.status === 'pending' ? 'player.place.mine_waiting' : 'player.place.mine_confirmed') }}</h3>
                        <button type="button" class="x" :aria-label="$t('common.close')" @click="closeSheets"><Icon name="x" /></button>
                    </div>
                    <p class="muted" style="margin: 0 0 12px; font-size: 14px">
                        {{ dateLong(date) }} · {{ cname(field) }} · {{ hhmm(mineOpen.start) }}–{{ hhmm(mineOpen.end) }}
                    </p>
                    <div class="row" style="gap: 8px; margin-bottom: 14px">
                        <StatusPill :status="mineOpen.status" />
                        <span class="pill mono">{{ mineOpen.code }}</span>
                    </div>
                    <p v-if="mineOpen.status === 'pending'" style="margin: 0 0 14px; font-size: 14px">{{ $t('player.place.mine_waiting_sub') }}</p>
                    <div class="minebtns">
                        <Link class="btn block" :href="route('me.bookings')"><Icon name="ticket" size="sm" />{{ $t('common.my_bookings') }}</Link>
                        <button type="button" class="btn outline block hot" :disabled="cancelBusy" @click="cancelMine">
                            {{ $t(mineOpen.status === 'pending' ? 'player.my_bookings.withdraw' : 'player.my_bookings.cancel') }}
                        </button>
                    </div>
                </template>
                <template v-else-if="sheet === 'taken' && takenAt != null && field">
                    <div class="row" style="margin-bottom: 6px">
                        <h3 id="vt">{{ $t('player.alert.title', { t: hhmm(takenAt) }) }}</h3>
                        <button type="button" class="x" :aria-label="$t('common.close')" @click="closeSheets"><Icon name="x" /></button>
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
                        <button type="button" class="btn block" :disabled="alertBusy" @click="watchTaken"><Icon name="bell" size="sm" />{{ $t('player.alert.button') }}</button>
                    </template>
                </template>
                <template v-else>
                    <form @submit.prevent="submit">
                        <div class="row" style="margin-bottom: 6px">
                            <h3 id="vt">{{ $t('player.verify.name_only') }}</h3>
                            <button type="button" class="x" :aria-label="$t('common.close')" @click="closeSheets"><Icon name="x" /></button>
                        </div>
                        <div class="field" style="margin: 12px 0 16px">
                            <label for="vn2">{{ $t('common.your_name') }}</label>
                            <input id="vn2" v-model="form.name" class="input" autocomplete="name" maxlength="60" :placeholder="$t('player.verify.name_placeholder')" />
                            <div v-if="form.errors.name" class="err">{{ form.errors.name }}</div>
                        </div>
                        <button class="btn block" :disabled="form.processing || form.name.trim().length < 2"><Icon name="send" size="sm" />{{ $t('player.verify.send_request') }}</button>
                    </form>
                </template>
            </div>
        </div>

        <!-- request sent -->
        <div v-if="okBooking" class="scrim" @click.self="okBooking = null">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="okT">
                <div class="row"><button type="button" class="x" :aria-label="$t('common.close')" @click="okBooking = null"><Icon name="x" /></button></div>
                <div class="okhead">
                    <div class="okmark"><Icon name="send" /></div>
                    <h3 id="okT">{{ $t('player.request_sent.title') }}</h3>
                    <div class="muted" style="font-size: 14px; margin-top: 4px">{{ $t('player.request_sent.sub') }}</div>
                </div>
                <div class="okcode"><small>{{ $t('player.request_sent.request_number') }}</small>{{ okBooking.code }}</div>
                <dl class="oklist">
                    <dt>{{ $t('common.status') }}</dt>
                    <dd><StatusPill :status="okBooking.status" /></dd>
                    <dt>{{ $t('player.request_sent.venue') }}</dt>
                    <dd>{{ okBooking.venue.name }}</dd>
                    <dt>{{ $t('common.field') }}</dt>
                    <dd>{{ $t(`player.size.${okBooking.field.size}`) }} {{ okBooking.field.format }}</dd>
                    <dt>{{ $t('common.when') }}</dt>
                    <dd>{{ dateLong(okBooking.date) }} · {{ okBooking.start }}–{{ okBooking.end }}</dd>
                    <template v-if="okBooking.venue.address">
                        <dt>{{ $t('common.address') }}</dt>
                        <dd>{{ okBooking.venue.address }}</dd>
                    </template>
                    <template v-if="okBooking.teamName">
                        <dt>{{ $t('common.team') }}</dt>
                        <dd>{{ okBooking.teamName }}</dd>
                    </template>
                    <dt>{{ $t('player.request_sent.price') }}</dt>
                    <dd>{{ amd(okBooking.price) }}</dd>
                </dl>
                <div v-if="user && !user.telegram && canLinkTelegram" class="tg-note" style="margin: 0 0 12px">
                    <Icon name="send" size="sm" /><span style="flex: 1">{{ $t('player.request_sent.telegram_hint') }}</span>
                </div>
                <div v-if="user && !user.telegram && canLinkTelegram" class="tg-box">
                    <TelegramConnect compact />
                </div>
                <!-- the team gets the news and the reminders too -->
                <div v-if="has('team')" class="okteam">
                    <Icon name="users" />
                    <div>
                        <b>{{ $t('player.team.invite_title') }}</b>
                        <p class="muted">{{ $t('player.team.invite_sub') }}</p>
                    </div>
                </div>
                <div class="okacts">
                    <Link class="btn block" :href="route('me.bookings', { team: okBooking.id })"><Icon name="users" size="sm" />{{ $t('player.team.add') }}</Link>
                    <Link class="btn ghost block" :href="route('me.bookings')"><Icon name="ticket" size="sm" />{{ $t('common.my_bookings') }}</Link>
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
.purpose { margin: 4px 0 14px; }
.purpose button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; white-space: normal; line-height: 1.25; text-align: center; }
.purpose button small { display: block; font-weight: 700; opacity: .8; }
.clubs { display: grid; gap: 12px; }
.mycard { display: flex; align-items: center; gap: 14px; }
.mycard > .ico { color: var(--accent); flex: none; }
.mycard .mc { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.mycard .mc small { color: var(--muted); font-weight: 700; font-size: 13px; }
.crow { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--line); color: var(--ink); }
.crow:last-of-type { border-bottom: 0; }
.crow .w { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.crow small { color: var(--muted); font-size: 12.5px; }
.prods { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }
.prod { display: flex; flex-direction: column; gap: 3px; font-size: 13.5px; }
.prod.out { opacity: .55; }
.prod .img { aspect-ratio: 1; border-radius: 12px; overflow: hidden; background: var(--surface-2); display: grid; place-items: center; color: var(--faint); }
.prod .img img { width: 100%; height: 100%; object-fit: cover; }
.prod .pr { font-family: var(--mono); font-weight: 700; }
.prod .pr s { color: var(--faint); font-weight: 400; }
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
/* my own request (waiting) or booking (confirmed): blue "yours", tappable */
.tslot.mine { background: var(--you-soft); color: var(--you); border: 1px solid rgba(92, 200, 255, .4); }
.tslot.mine.pending { border-style: dashed; }
.tslot.mine.wide { width: auto; padding: 8px 12px; }
.minerow { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 10px; font-size: 13px; font-weight: 700; }
.minebtns { display: grid; gap: 8px; }
.btn.outline.hot { color: var(--hot); border-color: rgba(255, 107, 107, .4); }
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
.tgl .you { background: var(--you-soft); box-shadow: inset 0 0 0 1px rgba(92, 200, 255, .5); }
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
  .bookbar { display: flex; position: fixed; left: 10px; right: 10px; bottom: calc(10px + var(--sab)); z-index: 45; align-items: center; gap: 10px; padding: 8px 8px 8px 14px; border-radius: 18px; background: rgba(27, 33, 28, .96); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--line-2); box-shadow: var(--shadow); }
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
