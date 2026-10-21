<script setup lang="ts">
import CodeInput from '@/components/CodeInput.vue';
import Icon from '@/components/Icon.vue';
import CourtSvg from '@/components/player/CourtSvg.vue';
import StatusPill from '@/components/player/StatusPill.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import { amd, hhmm, useShared } from '@/composables/useShared';
import { SIZE_COLOR, dateLong, dateRel, dateShort, dur, pitchColor, toHours, wdName } from '@/composables/usePlayer';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { SharedData } from '@/types';
import type { DayInfo, FieldInfo, PlayerBooking, SlotsPayload } from '@/types/player';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Place page: the two fields, a day, a duration and a free start time → price → "Send request".
// Guests confirm their phone with a code once (then they stay logged in).
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
    rules: { freeCancelHours: number; minNoticeMin: number; horizonDays: number };
}
const props = defineProps<{
    venue: VenueInfo;
    fields: FieldInfo[];
    days: DayInfo[];
    initial: { date: string; field: number | null; minutes: number | null; start: string | null };
    slots: SlotsPayload;
    booked: PlayerBooking | null;
}>();
const page = usePage<SharedData>();
const { user } = useShared();
const today = computed(() => props.days[0]?.date);

// ---------- state ----------
const date = ref(props.initial.date);
const fieldId = ref<number | null>(props.initial.field ?? props.fields[0]?.id ?? null);
const minutes = ref<number>(props.initial.minutes ?? 60);
const start = ref<number | null>(null);
const slots = ref<SlotsPayload>(props.slots);
const loading = ref(false);
const tab = ref<'fields' | 'info'>('fields');
const notice = ref<string | null>(null);

const field = computed(() => props.fields.find((f) => f.id === fieldId.value) ?? null);
const durFor = (f: FieldInfo) => (f.durations.includes(minutes.value) ? minutes.value : f.defaultDuration);
const startsFor = (f: FieldInfo | null, m: number) => (f ? (slots.value.fields.find((x) => x.id === f.id)?.starts[String(m)] ?? []) : []);
const free = computed(() => startsFor(field.value, minutes.value));
const same = (a: number, b: number) => Math.abs(a - b) < 1e-6;

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
function nearest(list: number[], t: number | null): number | null {
    if (!list.length) return null;
    if (t == null) return list[0];
    return list.reduce((a, b) => (Math.abs(b - t) < Math.abs(a - t) ? b : a));
}
/** first visit / new day: the asked time, else the first free one (evenings first on later days) */
function autoPick(wanted: number | null) {
    const list = free.value;
    if (wanted != null && list.some((t) => same(t, wanted))) return (start.value = wanted);
    if (wanted != null) return (start.value = nearest(list, wanted));
    const order = date.value === today.value ? list : [...list.filter((t) => t >= 18), ...list.filter((t) => t < 18)];
    start.value = order[0] ?? null;
}
/** keep the choice valid after the day / duration / field changes */
function revalidate() {
    if (start.value != null && free.value.some((t) => same(t, start.value!))) return;
    start.value = nearest(free.value, start.value);
}
function pickField(id: number) {
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
function pickDay(d: string) {
    if (d === date.value) return;
    date.value = d;
    load().then(() => autoPick(start.value));
}
autoPick(props.initial.start ? toHours(props.initial.start) : null);
// nothing free on the chosen field: when the visitor did not ask for a field, switch to one that has free times;
// when nothing is free today at all, show tomorrow
if (start.value == null && !props.initial.start) {
    const asked = new URLSearchParams(window.location.search).has('field');
    const other = asked ? null : props.fields.find((f) => startsFor(f, durFor(f)).length);
    if (other) {
        pickField(other.id);
        autoPick(null);
    } else if (!asked && date.value === today.value && props.days[1] && !new URLSearchParams(window.location.search).has('date')) {
        setTimeout(() => pickDay(props.days[1].date));
    }
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
        if (quiet && before != null && (start.value == null || !same(before, start.value))) notice.value = trans('player.place.refreshed');
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
const stText = (st: St) => trans(st === 'sel' ? 'player.place.stSel' : st === 'free' ? 'player.place.stFree' : 'player.place.stBusy');

// ---------- summary ----------
const summary = computed(() => {
    const f = field.value;
    if (!f || start.value == null) return null;
    const p = priceOf(f, start.value, minutes.value);
    const wd = props.days.find((d) => d.date === date.value);
    return { f, ...p, weekday: wd ? !wd.weekend : true };
});
const hoursToday = computed(() => slots.value.hours);
const minPrice = computed(() => Math.min(...props.fields.map((f) => f.price.min)));
const maxPrice = computed(() => Math.max(...props.fields.map((f) => f.price.max)));
const weekHours = computed(() => {
    const w = props.venue.week;
    if (w.length === 7 && w.every((x) => x[1] === w[0][1] && x[2] === w[0][2])) return [{ label: null as string | null, open: w[0][1], close: w[0][2] }];
    return [1, 2, 3, 4, 5, 6, 0].map((d) => {
        const x = w.find((y) => y[0] === d);
        return { label: wdName(d), open: x?.[1] ?? null, close: x?.[2] ?? null };
    });
});
const AMEN_ICON: Record<string, string> = { parking: 'car', showers: 'shower', lockers: 'locker', cafe: 'coffee', lights: 'bolt', bibs: 'ball', stands: 'users' };

// ---------- request ----------
const form = useForm({ field_id: 0, date: '', start: '', minutes: 60, team_name: '', note: '', name: '', code: '' });
const phoneForm = useForm({ name: '', phone: '' });
const sheet = ref<null | 'phone' | 'code' | 'name'>(null);
const codeBox = ref<InstanceType<typeof CodeInput>>();
const okBooking = ref<PlayerBooking | null>(null);
const phoneShown = ref('');

function book() {
    if (!summary.value) return;
    form.clearErrors();
    notice.value = null;
    if (!user.value) {
        phoneForm.clearErrors();
        sheet.value = 'phone';
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
            form.reset('team_name', 'note', 'code');
        },
        onError: (e) => {
            if (e.code) {
                form.code = '';
                codeBox.value?.clear();
                return;
            }
            sheet.value = null;
            if (e.booking) load(true);
        },
    });
}
function sendCode() {
    phoneForm.post(route('bookings.code'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            phoneShown.value = '+374 ' + phoneForm.phone.replace(/\D/g, '').slice(-8).replace(/(\d{2})(\d{3})(\d{3})/, '$1 $2 $3');
            form.name = phoneForm.name;
            form.clearErrors();
            sheet.value = 'code';
        },
    });
}
function onCode(code: string) {
    form.code = code;
    submit();
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
function scrollToPanel() {
    document.getElementById('panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
const homeUrl = computed(() => route('home', date.value !== today.value ? { date: date.value } : {}));
</script>

<template>
    <PlayerLayout>
        <Head :title="venue.name" />
        <div class="pg">
            <Link class="backlink" :href="homeUrl"><Icon name="back" size="sm" />{{ $t('player.place.back') }}</Link>

            <!-- header -->
            <section class="vhead">
                <div style="min-width: 0">
                    <span class="kicker">{{ $t(`player.district.${venue.district}`) }} · {{ $t('player.place.twoFields') }}</span>
                    <h1>{{ venue.name }}</h1>
                    <div class="vmeta">
                        <span v-if="venue.rating" class="rating"><Icon name="star" size="sm" />{{ venue.rating.toFixed(1) }}</span>
                        <span v-if="venue.reviews">{{ $t('player.reviews', { n: venue.reviews }) }}</span>
                        <span v-if="venue.address"><Icon name="pin" size="sm" />{{ venue.address }}, {{ $t(`player.district.${venue.district}`) }}</span>
                        <span v-if="hoursToday"><Icon name="clock" size="sm" />{{ $t('player.place.open', { a: hhmm(hoursToday[0]), b: hhmm(hoursToday[1]) }) }}</span>
                        <span v-else><Icon name="clock" size="sm" />{{ $t('player.place.closedDay') }}</span>
                    </div>
                    <div class="vtags">
                        <span v-for="f in fields" :key="f.id" class="pill" :style="{ color: SIZE_COLOR[f.size], background: `color-mix(in srgb, ${SIZE_COLOR[f.size]} 13%, transparent)` }">
                            <Icon :name="f.indoor ? 'roof' : 'sun'" size="sm" />{{ cname(f) }} · {{ $t(`player.surface.${f.surface}`) }}
                        </span>
                        <span v-for="a in venue.amenities" :key="a" class="pill amen"><Icon :name="AMEN_ICON[a] || 'check'" />{{ $t(`player.amenity.${a}`) }}</span>
                    </div>
                    <p v-if="venue.description" class="about">{{ venue.description }}</p>
                </div>
                <div class="vcta">
                    <span class="price">{{ $t('player.from') }} {{ amd(minPrice) }} <small>{{ $t('player.perHour') }}</small></span>
                    <button type="button" class="btn" @click="scrollToPanel"><Icon name="send" size="sm" />{{ $t('player.place.book') }}</button>
                </div>
            </section>

            <section class="bookgrid">
                <!-- the two fields with their status at the chosen time -->
                <div class="card courtsbox">
                    <div class="mhead">
                        <div>
                            <span class="kicker">{{ $t('player.place.mapK') }}</span>
                            <h2>{{ $t('player.place.mapT') }}</h2>
                        </div>
                        <div class="when" aria-live="polite">
                            <Icon name="clock" size="sm" />{{ dateRel(date, today) }}<template v-if="start != null"> · {{ range(start, minutes) }}</template>
                        </div>
                    </div>
                    <div class="legend">
                        <span><i style="background: var(--free)" />{{ $t('player.place.stFree') }}</span>
                        <span><i style="background: var(--busy-ink)" />{{ $t('player.place.stBusy') }}</span>
                        <span><i style="background: var(--accent)" />{{ $t('player.place.stSel') }}</span>
                    </div>
                    <div class="tiles">
                        <button v-for="t in tiles" :key="t.f.id" type="button" :class="['tile', `s-${t.st}`]" :aria-pressed="t.st === 'sel'" @click="pickField(t.f.id)">
                            <span class="art">
                                <CourtSvg :size="t.f.size" :color="pitchColor(t.f.surface)" />
                                <span class="pill st">{{ stText(t.st) }}</span>
                                <span class="sp" :style="{ color: SIZE_COLOR[t.f.size] }">{{ t.f.size === 'small' ? '5' : '11' }}</span>
                            </span>
                            <span class="tb">
                                <b>{{ cname(t.f) }}</b>
                                <span class="m">{{ $t(`player.surface.${t.f.surface}`) }} · {{ t.f.indoor ? $t('player.indoor') : $t('player.outdoor') }} · {{ $t('player.players', { n: t.f.players }) }}</span>
                                <span v-if="t.line" class="p">{{ t.line }}</span>
                            </span>
                        </button>
                    </div>
                    <div class="steps3">
                        <div v-for="[ic, i] in [['clock', 1], ['send', 2], ['check', 3]] as const" :key="i">
                            <Icon :name="ic" /><b>{{ $t(`player.place.s${i}`) }}</b><small>{{ $t(`player.place.s${i}s`) }}</small>
                        </div>
                    </div>
                </div>

                <!-- booking panel -->
                <aside id="panel" class="card panel">
                    <div>
                        <span class="kicker">{{ $t('player.place.k') }}</span>
                        <h2>{{ $t('player.place.title') }}</h2>
                    </div>
                    <div>
                        <div class="plabel">{{ $t('player.place.date') }}</div>
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
                    <div>
                        <div class="plabel">{{ $t('player.place.field') }}</div>
                        <div class="chips wrapc">
                            <button v-for="f in fields" :key="f.id" type="button" class="chip" :aria-pressed="fieldId === f.id" @click="pickField(f.id)">
                                <span class="sport-dot" :style="{ background: SIZE_COLOR[f.size] }" />{{ cname(f) }}
                            </button>
                        </div>
                    </div>
                    <div v-if="field">
                        <div class="plabel">{{ $t('player.place.dur') }}</div>
                        <div class="chips">
                            <button v-for="m in field.durations" :key="m" type="button" class="chip" :aria-pressed="minutes === m" @click="pickDur(m)"><Icon name="clock" size="sm" />{{ dur(m) }}</button>
                        </div>
                    </div>
                    <div v-if="field">
                        <div class="plabel">
                            <span>{{ $t('player.place.time') }}</span>
                            <span v-if="loading" class="faint" style="text-transform: none; letter-spacing: 0">{{ $t('player.place.loading') }}</span>
                        </div>
                        <div :class="['flist', { dim: loading }]">
                            <div class="fr">
                                <b>
                                    <span class="sport-dot" :style="{ background: SIZE_COLOR[field.size] }" />{{ cname(field) }}
                                    <span class="faint" style="font-weight: 600; font-size: 12px">{{ dur(minutes) }} · {{ field.indoor ? $t('player.indoor') : $t('player.outdoor') }}</span>
                                </b>
                                <div class="slots">
                                    <template v-if="free.length">
                                        <button
                                            v-for="t in free"
                                            :key="t"
                                            type="button"
                                            :class="['slot-mini', { prime: isPrime(field, t), on: start != null && same(t, start) }]"
                                            :aria-pressed="start != null && same(t, start)"
                                            @click="start = t"
                                        >
                                            {{ hhmm(t) }}
                                        </button>
                                    </template>
                                    <span v-else class="faint" style="font-size: 13px">{{ $t('player.place.noFree', { dur: dur(minutes) }) }}</span>
                                </div>
                                <div v-if="free.some((t) => isPrime(field!, t))" class="tgl"><span><i style="background: var(--warn-soft); box-shadow: inset 0 0 0 1px rgba(255, 181, 71, 0.4)" />{{ $t('player.place.primeTag') }}</span></div>
                            </div>
                        </div>
                    </div>

                    <div v-if="notice" class="err-box warn"><Icon name="clock" size="sm" /><span>{{ notice }}</span></div>
                    <div v-if="form.errors.booking" class="err-box" role="alert"><Icon name="x" size="sm" /><span>{{ form.errors.booking }}</span></div>

                    <div v-if="!summary" class="sum empty-sum">
                        <Icon name="calendar" size="lg" /><span>{{ $t('player.place.pick') }}</span>
                    </div>
                    <div v-else class="sum">
                        <div class="row between" style="align-items: flex-start">
                            <div style="min-width: 0">
                                <span class="kicker">{{ $t('player.place.your') }}</span>
                                <h3>{{ cname(summary.f) }}</h3>
                                <div class="muted" style="font-size: 13px">
                                    {{ $t(`player.surface.${summary.f.surface}`) }} · {{ summary.f.indoor ? $t('player.indoor') : $t('player.outdoor') }} · {{ $t('player.players', { n: summary.f.players }) }}
                                </div>
                            </div>
                            <div class="thumb"><CourtSvg :size="summary.f.size" :color="pitchColor(summary.f.surface)" /></div>
                        </div>
                        <div class="facts3">
                            <div><span>{{ $t('player.place.whenL') }}</span><b>{{ dateRel(date, today) }}{{ date === today || dateRel(date, today) === $t('player.tomorrow') ? ', ' + dateShort(date) : '' }}</b></div>
                            <div><span>{{ $t('player.place.timeL') }}</span><b>{{ range(start!, minutes) }}</b></div>
                            <div><span>{{ $t('player.place.durL') }}</span><b>{{ dur(minutes) }}</b></div>
                        </div>
                        <div class="total">
                            <div>
                                <div v-if="summary.primeMin && summary.dayMin" class="rate prime"><Icon name="bolt" size="sm" />{{ $t('player.place.mixed', { a: dur(summary.dayMin), b: dur(summary.primeMin) }) }}</div>
                                <div v-else-if="summary.primeMin" class="rate prime"><Icon name="bolt" size="sm" />{{ $t('player.place.primeRate', { p: amd(summary.f.price.max) }) }}</div>
                                <div v-else class="rate"><Icon name="sun" size="sm" />{{ $t('player.place.dayRate', { p: amd(summary.f.price.min) }) }}</div>
                            </div>
                            <div style="text-align: right">
                                <div class="faint" style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em">{{ $t('player.place.total') }}</div>
                                <div class="amt">{{ amd(summary.total) }}</div>
                            </div>
                        </div>
                        <div v-if="summary.primeMin && summary.weekday && summary.f.price.min < summary.f.price.max" class="note">
                            <Icon name="sun" size="sm" />{{ $t('player.place.save', { p: amd((summary.f.price.min * minutes) / 60) }) }}
                        </div>
                        <div v-else-if="summary.primeMin" class="note"><Icon name="bolt" size="sm" />{{ $t('player.place.primeNote') }}</div>
                        <div class="opt">
                            <div class="field">
                                <label for="bkTeam">{{ $t('player.place.team') }}</label>
                                <input id="bkTeam" v-model="form.team_name" class="input" maxlength="40" :placeholder="$t('player.place.teamPh')" />
                            </div>
                            <div class="field">
                                <label for="bkNote">{{ $t('player.place.note') }}</label>
                                <textarea id="bkNote" v-model="form.note" class="input" rows="2" maxlength="200" :placeholder="$t('player.place.notePh')" />
                            </div>
                        </div>
                        <div v-if="user && user.name" class="note"><Icon name="user" size="sm" /><span>{{ $t('player.place.as', { name: user.name, phone: user.phone }) }}</span></div>
                        <button type="button" class="btn block" :disabled="form.processing" @click="book">
                            <Icon name="send" size="sm" />{{ $t('player.place.send', { p: amd(summary.total) }) }}
                        </button>
                        <div class="note"><Icon name="send" size="sm" /><span>{{ $t('player.place.how', { h: venue.rules.freeCancelHours }) }}</span></div>
                    </div>
                </aside>
            </section>

            <!-- lower sections -->
            <section class="section">
                <div class="tabs" role="tablist">
                    <button role="tab" :aria-selected="tab === 'fields'" @click="tab = 'fields'"><Icon name="grid" size="sm" />{{ $t('player.place.tabFields') }}<span class="n">{{ fields.length }}</span></button>
                    <!-- later: open games and tournaments of this place -->
                    <button role="tab" :aria-selected="tab === 'info'" @click="tab = 'info'"><Icon name="pin" size="sm" />{{ $t('player.place.tabInfo') }}</button>
                </div>
                <div v-if="tab === 'fields'" class="grid c3">
                    <div v-for="f in fields" :key="f.id" class="card ccard">
                        <div class="thumb"><CourtSvg :size="f.size" :color="pitchColor(f.surface)" /></div>
                        <div class="body">
                            <div class="row between">
                                <h3>{{ $t(`player.size.${f.size}`) }}</h3>
                                <span class="pill" :style="{ color: SIZE_COLOR[f.size] }"><Icon name="ball" size="sm" />{{ f.format }}</span>
                            </div>
                            <div class="pills">
                                <span class="pill">{{ $t('player.players', { n: f.players }) }}</span>
                                <span class="pill">{{ $t(`player.surface.${f.surface}`) }}</span>
                                <span class="pill"><Icon :name="f.indoor ? 'roof' : 'sun'" size="sm" />{{ f.indoor ? $t('player.indoor') : $t('player.outdoor') }}</span>
                            </div>
                            <div class="prices">
                                <div><span>{{ $t('player.place.ctDay') }} {{ $t('player.perHour') }}</span><b>{{ amd(f.price.min) }}</b></div>
                                <div class="pr"><span>{{ $t('player.place.ctPrime') }} {{ $t('player.perHour') }}</span><b>{{ amd(f.price.max) }}</b></div>
                            </div>
                            <div class="acts">
                                <button type="button" class="btn sm" @click="pickField(f.id); scrollToPanel()"><Icon name="clock" size="sm" />{{ $t('player.place.ctBook') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="info">
                    <div class="card">
                        <h3><Icon name="clock" />{{ $t('player.place.infoHours') }}</h3>
                        <div v-for="(h, i) in weekHours" :key="i" class="hours">
                            <span class="muted">{{ h.label ?? '7/7' }}</span>
                            <b>{{ h.open != null ? `${hhmm(h.open)} – ${hhmm(h.close!)}` : '—' }}</b>
                        </div>
                    </div>
                    <div class="card">
                        <h3><Icon name="pin" />{{ $t('player.place.infoAddr') }}</h3>
                        <div style="font-weight: 700">{{ venue.address }}</div>
                        <div class="muted" style="font-size: 14px">{{ $t(`player.district.${venue.district}`) }}, {{ $t('player.place.infoCity') }}</div>
                        <h3 style="margin-top: 18px"><Icon name="wallet" />{{ $t('player.place.infoPrices') }}</h3>
                        <div class="muted" style="font-size: 14px">{{ amd(minPrice) }} – {{ amd(maxPrice) }} {{ $t('player.perHour') }}</div>
                    </div>
                    <div class="card">
                        <h3><Icon name="check" />{{ $t('player.place.infoRules') }}</h3>
                        <ul>
                            <li><Icon name="check" size="sm" /><span>{{ $t('player.place.r1') }}</span></li>
                            <li><Icon name="check" size="sm" /><span>{{ $t('player.place.r2', { h: venue.rules.freeCancelHours }) }}</span></li>
                            <li><Icon name="check" size="sm" /><span>{{ $t('player.place.r3', { m: venue.rules.minNoticeMin }) }}</span></li>
                            <li><Icon name="check" size="sm" /><span>{{ $t('player.place.r4') }}</span></li>
                        </ul>
                    </div>
                    <div v-if="venue.amenities.length" class="card">
                        <h3><Icon name="star" />{{ $t('player.place.infoAmen') }}</h3>
                        <ul>
                            <li v-for="a in venue.amenities" :key="a"><Icon :name="AMEN_ICON[a] || 'check'" size="sm" /><span>{{ $t(`player.amenity.${a}`) }}</span></li>
                        </ul>
                    </div>
                </div>
            </section>
        </div>

        <!-- guest: name + phone → code -->
        <div v-if="sheet" class="scrim" @click.self="closeSheets">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="vt">
                <template v-if="sheet === 'phone'">
                    <form @submit.prevent="sendCode">
                        <div class="row" style="margin-bottom: 6px">
                            <h3 id="vt">{{ $t('player.verify.title') }}</h3>
                            <button type="button" class="x" :aria-label="$t('player.close')" @click="closeSheets"><Icon name="x" /></button>
                        </div>
                        <p class="muted" style="margin: 0 0 18px; font-size: 14px">{{ $t('player.verify.sub') }}</p>
                        <div class="field" style="margin-bottom: 12px">
                            <label for="vn">{{ $t('player.verify.name') }}</label>
                            <input id="vn" v-model="phoneForm.name" class="input" autocomplete="name" maxlength="60" :placeholder="$t('player.verify.namePh')" />
                            <div v-if="phoneForm.errors.name" class="err">{{ phoneForm.errors.name }}</div>
                        </div>
                        <div class="field" style="margin-bottom: 6px">
                            <label for="vp">{{ $t('player.verify.phone') }}</label>
                            <div class="row" style="gap: 8px">
                                <span class="input" style="width: auto; color: var(--muted)">+374</span>
                                <input id="vp" v-model="phoneForm.phone" class="input mono" inputmode="tel" autocomplete="tel" placeholder="91 234 567" />
                            </div>
                            <div v-if="phoneForm.errors.phone" class="err">{{ phoneForm.errors.phone }}</div>
                        </div>
                        <div class="tg-note"><Icon name="send" size="sm" />{{ $t('player.verify.tgNote') }}</div>
                        <button class="btn block" :disabled="phoneForm.processing || !phoneForm.name.trim() || !phoneForm.phone.trim()">{{ $t('player.verify.send') }}</button>
                    </form>
                </template>
                <template v-else-if="sheet === 'code'">
                    <div class="row" style="margin-bottom: 6px">
                        <button type="button" class="x" style="margin: 0" :aria-label="$t('player.back')" @click="sheet = 'phone'"><Icon name="back" /></button>
                        <h3 id="vt" style="margin-left: 6px">{{ $t('player.verify.title') }}</h3>
                        <button type="button" class="x" :aria-label="$t('player.close')" @click="closeSheets"><Icon name="x" /></button>
                    </div>
                    <p class="muted" style="font-size: 14px">{{ $t('player.verify.enter', { phone: phoneShown }) }}</p>
                    <CodeInput ref="codeBox" @complete="onCode" />
                    <p v-if="page.props.devCode" style="text-align: center; margin: 14px 0 4px">
                        <span class="pill lime">{{ $t('auth.devCode', { code: page.props.devCode }) }}</span>
                    </p>
                    <div v-if="form.processing" class="muted" style="text-align: center; font-size: 13px; margin-top: 8px">{{ $t('player.verify.sending') }}</div>
                    <div v-if="form.errors.code" class="err" style="text-align: center">{{ form.errors.code }}</div>
                    <div v-if="phoneForm.errors.phone" class="err" style="text-align: center">{{ phoneForm.errors.phone }}</div>
                    <div class="row" style="justify-content: space-between; margin-top: 18px">
                        <button type="button" class="btn ghost sm" @click="sheet = 'phone'"><Icon name="back" size="sm" />{{ $t('player.verify.change') }}</button>
                        <button type="button" class="btn ghost sm" :disabled="phoneForm.processing" @click="sendCode">{{ $t('player.verify.resend') }}</button>
                    </div>
                    <p class="faint" style="font-size: 12px; text-align: center; margin: 14px 0 0">{{ $t('player.verify.noTg') }}</p>
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
                <div class="okacts">
                    <Link class="btn block" :href="route('me.bookings')"><Icon name="ticket" size="sm" />{{ $t('player.ok.my') }}</Link>
                </div>
            </div>
        </div>
    </PlayerLayout>
</template>

<style scoped>
.pg { overflow-x: clip; }
.backlink { display: inline-flex; align-items: center; gap: 8px; color: var(--muted); font-weight: 700; font-size: 14px; margin: 20px 0 14px; }
.backlink:hover { color: var(--accent); }

/* header */
.vhead { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 18px 32px; align-items: end; margin-bottom: 22px; }
.vhead h1 { font-size: clamp(28px, 4.2vw, 46px); margin: 6px 0 10px; overflow-wrap: anywhere; }
.vmeta { display: flex; flex-wrap: wrap; gap: 8px 18px; color: var(--muted); font-size: 14px; align-items: center; }
.vmeta span { display: inline-flex; align-items: center; gap: 6px; }
.vmeta .rating { font-size: 15px; }
.vtags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 14px; }
.vtags .pill { padding: 5px 11px; font-size: 12.5px; }
.vtags .amen { background: transparent; border: 1px solid var(--line-2); color: var(--muted); }
.vtags .amen .ico { width: 14px; height: 14px; }
.about { color: var(--muted); max-width: 700px; margin: 14px 0 0; font-size: 15px; }
.vcta { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
.vcta .price { font-size: 18px; }
@media (max-width: 760px) { .vhead { grid-template-columns: 1fr; } .vcta { flex-direction: row; justify-content: space-between; align-items: center; text-align: left; } }

/* fields + booking panel */
.bookgrid { display: grid; grid-template-columns: minmax(0, 1fr) 420px; gap: 18px; align-items: start; }
.courtsbox { padding: 18px; display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.mhead { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.mhead h2, .panel h2 { margin: 2px 0 0; font-size: 22px; }
.when { border-radius: 999px; padding: 7px 13px; font-weight: 700; font-size: 13px; display: inline-flex; gap: 7px; align-items: center; background: var(--bg-2); border: 1px solid var(--line-2); }
.when .ico { color: var(--accent); }
.legend { display: flex; gap: 14px; font-size: 12px; font-weight: 700; color: var(--muted); flex-wrap: wrap; }
.legend span { display: inline-flex; gap: 6px; align-items: center; }
.legend i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
.tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.tile { position: relative; text-align: left; padding: 0; border: 1px solid var(--line-2); border-radius: 18px; background: var(--surface-2); color: var(--ink); overflow: hidden; display: flex; flex-direction: column; transition: transform .15s, border-color .15s, box-shadow .2s, opacity .2s; min-width: 0; }
.tile:hover { transform: translateY(-2px); border-color: rgba(220, 255, 220, .3); }
.tile .art { position: relative; aspect-ratio: 16 / 9; background: radial-gradient(260px 120px at 50% 0%, rgba(255, 255, 230, .09), transparent 70%), #0c120e; display: grid; place-items: center; }
.tile .art svg { width: 82%; display: block; transition: filter .2s, opacity .2s; }
.tile .st { position: absolute; left: 10px; top: 10px; }
.tile .sp { position: absolute; right: 10px; top: 10px; width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; background: var(--glass); font-family: var(--mono); font-size: 12px; font-weight: 700; }
.tile .tb { padding: 11px 13px 13px; display: flex; flex-direction: column; gap: 3px; }
.tile .tb b { font-size: 15px; }
.tile .tb .m { font-size: 12.5px; color: var(--muted); }
.tile .tb .p { font-size: 13px; font-weight: 800; margin-top: 3px; }
.tile.s-free .st { background: var(--free-soft); color: var(--free); }
.tile.s-busy .art svg { filter: grayscale(.85) brightness(.55); }
.tile.s-busy .st { background: rgba(10, 13, 11, .8); color: var(--busy-ink); }
.tile.s-busy .tb .p { color: var(--muted); font-weight: 700; }
.tile.s-sel { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent), 0 14px 34px -14px rgba(200, 255, 61, .55); }
.tile.s-sel .st { background: var(--accent); color: var(--on-accent); }
.tile.s-sel .tb .p { color: var(--accent); }
.steps3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.steps3 div { background: var(--bg-2); border: 1px solid var(--line); border-radius: 14px; padding: 12px 14px; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.steps3 .ico { color: var(--accent); }
.steps3 b { font-size: 13.5px; }
.steps3 small { color: var(--muted); font-size: 12.5px; line-height: 1.35; }
@media (max-width: 600px) { .steps3 { grid-template-columns: 1fr; } .steps3 div { flex-direction: row; align-items: center; padding: 10px 12px; } .steps3 small { display: none; } }
.panel { padding: 18px; display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.plabel { font-size: 12px; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.day.td { padding: 7px 8px; }
.day small { display: block; overflow: hidden; text-overflow: ellipsis; padding: 0 3px; }
.day.we:not([aria-pressed="true"]) small { color: var(--warn); }
.chips.wrapc { flex-wrap: wrap; overflow: visible; }
.flist { display: flex; flex-direction: column; gap: 12px; transition: opacity .15s; }
.flist.dim { opacity: .5; }
.flist .fr b { font-size: 13.5px; display: flex; gap: 8px; align-items: center; margin-bottom: 8px; flex-wrap: wrap; }
.flist .slots { display: flex; gap: 6px; flex-wrap: wrap; }
.flist .slot-mini { cursor: pointer; }
.flist .slot-mini.prime { color: var(--warn); background: var(--warn-soft); border-color: rgba(255, 181, 71, .25); }
.flist .slot-mini.on { background: var(--accent); color: var(--on-accent); border-color: var(--accent); }
.tgl { display: flex; gap: 12px; flex-wrap: wrap; font-size: 11.5px; font-weight: 700; color: var(--muted); margin-top: 10px; }
.tgl span { display: inline-flex; gap: 5px; align-items: center; }
.tgl i { width: 12px; height: 12px; border-radius: 4px; display: inline-block; }
.err-box { display: flex; gap: 8px; align-items: flex-start; padding: 10px 12px; border-radius: 12px; background: var(--hot-soft); color: var(--hot); font-size: 13.5px; font-weight: 700; }
.err-box.warn { background: var(--warn-soft); color: var(--warn); }
.err-box .ico { margin-top: 2px; }

/* summary */
.sum { background: linear-gradient(180deg, var(--surface-2), var(--surface)); border: 1px solid var(--line-2); border-radius: 18px; padding: 16px; display: flex; flex-direction: column; gap: 14px; }
.sum.empty-sum { color: var(--muted); font-size: 14px; flex-direction: row; align-items: center; gap: 12px; border-style: dashed; background: none; }
.sum h3 { font-size: 18px; margin: 4px 0 2px; }
.sum .thumb { width: 84px; flex: none; border-radius: 8px; overflow: hidden; }
.sum .thumb svg { width: 100%; display: block; }
.facts3 { display: grid; grid-template-columns: 1.3fr 1fr .8fr; gap: 6px; }
.facts3 div { background: var(--bg-2); border-radius: 10px; padding: 8px 10px; min-width: 0; }
.facts3 span { display: block; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.facts3 b { font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
.total { display: flex; justify-content: space-between; align-items: end; gap: 10px; }
.total .amt { font-family: var(--display); font-size: 26px; font-weight: 600; letter-spacing: -.02em; line-height: 1; white-space: nowrap; }
.rate { font-size: 12.5px; color: var(--muted); display: flex; gap: 6px; align-items: center; }
.rate.prime { color: var(--warn); }
.note { font-size: 12px; color: var(--faint); display: flex; gap: 6px; align-items: flex-start; }
.note .ico { margin-top: 1px; }
.opt { display: grid; gap: 10px; }
.opt textarea.input { resize: vertical; min-height: 58px; }
@media (max-width: 1040px) { .bookgrid { grid-template-columns: 1fr; } }
@media (max-width: 600px) {
  .courtsbox, .panel { padding: 14px; }
  .tiles { gap: 10px; }
  .tile .tb { padding: 9px 10px 11px; }
  .tile .tb b { font-size: 14px; }
  .tile .sp { display: none; }
  .facts3 { grid-template-columns: 1fr 1fr; }
  .facts3 div:first-child { grid-column: 1 / -1; }
}

/* lower sections */
.tabs { margin-bottom: 18px; }
.tabs .n { font-size: 11px; background: var(--surface-3); color: var(--muted); border-radius: 99px; padding: 1px 7px; }
.ccard { overflow: hidden; display: flex; flex-direction: column; }
.ccard .thumb { height: 128px; background: radial-gradient(240px 110px at 50% 0%, rgba(255, 255, 230, .1), transparent 70%), #0c120e; display: grid; place-items: center; }
.ccard .thumb svg { height: 80%; width: auto; max-width: 70%; display: block; filter: drop-shadow(0 10px 14px rgba(0, 0, 0, .5)); }
.ccard .body { padding: 14px 16px 16px; display: flex; flex-direction: column; gap: 10px; flex: 1; }
.ccard .pills { display: flex; gap: 5px; flex-wrap: wrap; }
.ccard .prices { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.ccard .prices div { background: var(--bg-2); border-radius: 10px; padding: 7px 10px; }
.ccard .prices span { display: block; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--faint); }
.ccard .prices b { font-size: 14.5px; }
.ccard .prices .pr b { color: var(--warn); }
.ccard .acts { display: flex; gap: 8px; margin-top: auto; flex-wrap: wrap; }
.info { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
.info .card { padding: 18px; min-width: 0; }
.info h3 { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; font-size: 16px; }
.info h3 .ico { color: var(--accent); }
.info ul { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 10px; color: var(--muted); font-size: 14px; }
.info li { display: flex; gap: 9px; align-items: flex-start; }
.info li .ico { margin-top: 2px; color: var(--free); }
.hours { display: flex; justify-content: space-between; font-size: 14px; padding: 6px 0; border-bottom: 1px solid var(--line); }
.hours:last-child { border: 0; }

/* sheets */
.err { color: var(--hot); font-size: 13px; margin-top: 8px; }
.tg-note { display: flex; gap: 10px; align-items: center; margin: 14px 0 18px; padding: 10px 12px; border-radius: 12px; background: var(--blue-soft); color: var(--blue); font-size: 13px; font-weight: 700; }
.okhead { text-align: center; padding: 6px 0 4px; }
.okmark { width: 62px; height: 62px; border-radius: 50%; margin: 0 auto 12px; background: var(--grad); color: var(--on-accent); display: grid; place-items: center; box-shadow: 0 10px 30px -8px rgba(200, 255, 61, .6); }
.okmark .ico { width: 30px; height: 30px; stroke-width: 3; }
.okcode { font-family: var(--mono); font-size: 26px; letter-spacing: .12em; background: var(--bg-2); border: 1px dashed var(--line-2); border-radius: 14px; padding: 10px; text-align: center; margin: 14px 0; }
.okcode small { display: block; font-family: var(--body); font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); font-weight: 800; }
.oklist { display: grid; grid-template-columns: auto 1fr; gap: 8px 14px; font-size: 14px; margin: 0 0 16px; }
.oklist dt { color: var(--muted); font-weight: 700; }
.oklist dd { margin: 0; font-weight: 700; text-align: right; }
.okacts { display: grid; grid-template-columns: 1fr; gap: 8px; }
.tg-box { margin: -4px 0 12px; }
.tg-box :deep(.tg-connect) { padding: 0 !important; }
.tg-box :deep(.btn) { width: 100%; background: var(--blue); color: #04121b; }
</style>
