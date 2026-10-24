<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import {
    accept,
    askDecline,
    decisions,
    dotOf,
    fdate,
    longDate,
    openBooking,
    openForm,
    relDay,
    useOwner,
    weekdayOf,
    whoOf,
    type BookingRow,
    type Kind,
    type RequestRow,
} from '@/composables/useOwner';
import { amd, hhmm, useShared } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router, usePoll } from '@inertiajs/vue3';
import { trans } from '@/lib/i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Today: KPIs, the two-lane strip (what is on each field now and next), requests waiting (Accept / Decline with Undo),
// and the occupancy of the next 7 days at the bottom.
const props = defineProps<{
    date: string;
    me: string | null;
    kpi: {
        today: number;
        todayAccepted: number;
        todayPending: number;
        pending: number;
        occupancy: Record<number, number>;
        revenueToday: number;
        revenueWeek: number;
    };
    requests: RequestRow[];
    lanes: BookingRow[];
    chart: { date: string; fields: Record<number, number> }[];
    nowHour: number;
}>();
const owner = useOwner();
const { user } = useShared();
usePoll(30000, { only: ['kpi', 'requests', 'lanes', 'chart', 'owner', 'nowHour'] });

const fieldOf = (id: number) => owner.value.fields.find((f) => f.id === id);
const first = computed(() => (props.me || '').split(' ')[0]);

// the clock moves on while the page is open
const loadedAt = ref(Date.now());
const now = ref(props.nowHour);
let tick: number | undefined;
onMounted(() => (tick = window.setInterval(() => (now.value = props.nowHour + (Date.now() - loadedAt.value) / 36e5), 30000)));
onBeforeUnmount(() => clearInterval(tick));
watch(
    () => props.nowHour,
    (v) => {
        now.value = v;
        loadedAt.value = Date.now();
    },
);

// answered on this device, still inside the Undo window: hide from requests, show accepted ones as confirmed
const requestsShown = computed(() => props.requests.filter((b) => !decisions.hidden[b.id]));
const pendingCount = computed(() => Math.max(0, props.kpi.pending - (props.requests.length - requestsShown.value.length)));
const kindOf = (b: BookingRow): Kind => (decisions.hidden[b.id] === 'accepted' ? 'accepted' : b.kind);

// ---------- the today strip: one lane per field, the current booking + the next 3 ----------
const hoursToday = computed(() => owner.value.hours[weekdayOf(props.date)]);
const lanes = computed(() =>
    owner.value.fields
        .filter((f) => f.active)
        .map((f) => {
            const rows = props.lanes
                .filter((b) => b.field_id === f.id && b.date === props.date && decisions.hidden[b.id] !== 'declined')
                .sort((a, b) => a.start - b.start);
            const current = rows.find((b) => b.start <= now.value && b.end > now.value) ?? null;
            const later = rows.filter((b) => b.start > now.value);
            return {
                f,
                count: rows.length,
                current,
                progress: current ? Math.round(((now.value - current.start) / (current.end - current.start)) * 100) : 0,
                next: later.slice(0, 3),
                more: Math.max(0, later.length - 3),
            };
        }),
);
const closedToday = computed(() => !hoursToday.value || now.value >= hoursToday.value[1]);
const statusLabel = (k: Kind) => (k === 'pending' ? trans('owner.status.pending') : k === 'owner' ? trans('owner.status.owner') : '');

// conflicts: "Conflicts with Narek 20:00 · 11×11"
const conflictText = (c: RequestRow['conflicts'][number]) =>
    trans('owner.dashboard.conflict', { name: c.name, time: `${hhmm(c.start)} · ${fieldOf(c.field_id)?.format ?? ''}` });

// ---------- next 7 days ----------
const chartDays = computed(() =>
    props.chart.map((d, i) => ({
        date: d.date,
        top: i === 0 ? trans('common.today') : fdate(d.date, { weekday: 'short' }),
        day: fdate(d.date, { day: 'numeric' }),
        today: i === 0,
        bars: owner.value.fields.map((f) => ({ id: f.id, size: f.size, p: d.fields[f.id] ?? 0, label: f.label })),
    })),
);
</script>

<template>
    <OwnerLayout :title="$t('common.today')" :new-booking="{}">
        <div class="phead">
            <div>
                <div class="kicker">{{ longDate(date) }} · {{ owner.venue.name }}</div>
                <h1>{{ first ? $t('owner.dashboard.hello', { name: first }) : $t('common.today') }}</h1>
            </div>
            <div class="phead-r">
                <button type="button" class="btn new" data-test="new" @click="openForm({})">
                    <Icon name="plus" size="sm" />{{ $t('common.new_booking') }}
                </button>
            </div>
        </div>

        <div v-if="user && !user.telegram" class="tg-banner" data-test="tg-banner">
            <Icon name="send" />
            <div class="tgb-t">
                <b>{{ $t('owner.dashboard.telegram_title') }}</b>
                <small>{{ $t('owner.dashboard.telegram_sub') }}</small>
            </div>
            <TelegramConnect compact />
        </div>

        <div class="kpis">
            <div class="card kpi">
                <div class="lbl">
                    <Icon name="calendar" size="sm" /><span>{{ $t('common.bookings') }}</span>
                </div>
                <div class="val" data-test="kpi-today">{{ kpi.today }}</div>
                <div class="sub">{{ $t('owner.dashboard.kpi_bookings_sub', { a: kpi.todayAccepted, p: kpi.todayPending }) }}</div>
            </div>
            <div :class="['card kpi', { warn: pendingCount }]">
                <div class="lbl">
                    <Icon name="msg" size="sm" /><span>{{ $t('common.requests') }}</span>
                </div>
                <div class="val">{{ pendingCount }}</div>
                <div class="sub">{{ $t(pendingCount ? 'owner.dashboard.kpi_requests_sub' : 'owner.dashboard.kpi_requests_none') }}</div>
            </div>
            <div class="card kpi">
                <div class="lbl">
                    <Icon name="chart" size="sm" /><span>{{ $t('owner.dashboard.kpi_occupancy') }}</span>
                </div>
                <div class="occ">
                    <div v-for="f in owner.fields" :key="f.id">
                        <span>{{ f.format }}</span
                        ><span :class="['bar', dotOf(f.size)]"><i :style="{ width: (kpi.occupancy[f.id] ?? 0) + '%' }" /></span
                        ><b>{{ kpi.occupancy[f.id] ?? 0 }}%</b>
                    </div>
                </div>
            </div>
            <div class="card kpi">
                <div class="lbl">
                    <Icon name="wallet" size="sm" /><span>{{ $t('owner.dashboard.kpi_revenue') }}</span>
                </div>
                <div class="val money">{{ amd(kpi.revenueToday) }}</div>
                <div class="sub">{{ $t('owner.dashboard.kpi_revenue_sub', { sum: amd(kpi.revenueWeek) }) }}</div>
            </div>
        </div>

        <div class="dash">
            <section class="card panel tstrip" data-test="today-strip">
                <div class="sec-h">
                    <h2>{{ $t('owner.dashboard.strip') }}</h2>
                    <Link class="see-all" :href="route('owner.schedule')">{{ $t('owner.nav.schedule') }} <Icon name="arrow" size="sm" /></Link>
                </div>
                <div class="lanes">
                    <div v-for="l in lanes" :key="l.f.id" class="lane" data-test="lane">
                        <div class="lane-h">
                            <i :class="['fdot', dotOf(l.f.size)]" /><b>{{ l.f.format }}</b>
                            <small>{{ $t('owner.dashboard.lane_sub', { p: kpi.occupancy[l.f.id] ?? 0, n: l.count }) }}</small>
                        </div>
                        <button v-if="l.current" type="button" :class="['li cur', 'k-' + kindOf(l.current)]" @click="openBooking(l.current.id)">
                            <span class="li-t"
                                ><b>{{ $t('common.now') }}</b> · {{ $t('owner.dashboard.ends', { t: hhmm(l.current.end) }) }}</span
                            >
                            <span class="li-n">{{ l.current.team || l.current.name }}</span>
                            <span v-if="l.current.arrived" class="li-at ok"><Icon name="check" size="sm" />{{ $t('common.arrived') }}</span>
                            <span v-else-if="l.current.status === 'accepted'" class="li-at warn"
                                ><Icon name="clock" size="sm" />{{
                                    l.current.late_minutes ? $t('owner.booking.late', { min: String(l.current.late_minutes) }) : $t('owner.booking.waiting')
                                }}</span
                            >
                            <span class="prog"><i :style="{ width: l.progress + '%' }" /></span>
                        </button>
                        <div class="nowl" aria-hidden="true">
                            <span>{{ hhmm(now) }}</span>
                        </div>
                        <button
                            v-for="b in l.next"
                            :key="b.id"
                            type="button"
                            :class="['li', 'k-' + kindOf(b)]"
                            data-test="lane-item"
                            @click="openBooking(b.id)"
                        >
                            <span class="li-t"
                                >{{ hhmm(b.start) }}–{{ hhmm(b.end) }}<em v-if="statusLabel(kindOf(b))"> · {{ statusLabel(kindOf(b)) }}</em></span
                            >
                            <span class="li-n">{{ b.team || b.name }}</span>
                            <span v-if="b.late_minutes" class="li-at warn"><Icon name="clock" size="sm" />{{ $t('owner.booking.late', { min: String(b.late_minutes) }) }}</span>
                        </button>
                        <Link v-if="l.more" class="li-more" :href="route('owner.schedule')">{{
                            $t('owner.dashboard.more_later', { n: l.more })
                        }}</Link>
                        <p v-if="!l.next.length" class="li-free">
                            {{ closedToday ? $t('owner.dashboard.closed_today') : $t('owner.dashboard.free_rest') }}
                        </p>
                    </div>
                </div>
            </section>

            <section class="card panel reqs">
                <div class="sec-h">
                    <h2>
                        {{ $t('owner.dashboard.requests') }} <span v-if="pendingCount" class="pill warn">{{ pendingCount }}</span>
                    </h2>
                    <Link v-if="pendingCount" class="see-all" :href="route('owner.schedule', { view: 'list', status: 'pending' })"
                        >{{ $t('owner.see_all') }} <Icon name="arrow" size="sm"
                    /></Link>
                </div>
                <template v-if="requestsShown.length">
                    <div v-for="b in requestsShown" :key="b.id" class="req" data-test="request">
                        <div class="who" @click="openBooking(b.id)">
                            <div class="who-h">
                                <Avatar :id="b.user?.id" :name="b.name" :src="b.user?.avatar" size="sm" />
                                <span class="who-n"
                                    ><b>{{ b.name }}</b
                                    ><span v-if="b.team" class="muted"> · {{ b.team }}</span></span
                                >
                            </div>
                            <div class="meta">
                                <span><i :class="['fdot', dotOf(fieldOf(b.field_id)?.size || 'small')]" />{{ fieldOf(b.field_id)?.label }}</span>
                                <span><Icon name="calendar" size="sm" />{{ relDay(b.date, date) }} · {{ hhmm(b.start) }}–{{ hhmm(b.end) }}</span>
                                <span><Icon name="wallet" size="sm" />{{ amd(b.price) }}</span>
                                <a v-if="b.tel" class="tel" :href="`tel:${b.tel}`" data-test="tel" @click.stop
                                    ><Icon name="phone" size="sm" />{{ b.phone }}</a
                                >
                                <span v-if="b.sent_ago" class="faint">{{ $t('owner.dashboard.sent', { ago: b.sent_ago }) }}</span>
                            </div>
                            <div v-if="b.conflicts.length" class="conf">
                                <span v-for="c in b.conflicts" :key="c.id" class="cchip" data-test="conflict"
                                    ><Icon name="clock" size="sm" />{{ conflictText(c) }}</span
                                >
                            </div>
                        </div>
                        <div class="acts">
                            <button type="button" class="btn" data-test="accept" @click="accept(b.id, b)">
                                <Icon name="check" size="sm" />{{ $t('common.accept') }}
                            </button>
                            <button type="button" class="btn danger" data-test="decline" @click="askDecline(b.id, whoOf(b))">
                                <Icon name="x" size="sm" />{{ $t('common.decline') }}
                            </button>
                        </div>
                    </div>
                    <p v-if="pendingCount > requestsShown.length" style="margin: 12px 0 0">
                        <Link class="see-all" :href="route('owner.schedule', { view: 'list', status: 'pending' })">{{
                            $t('owner.dashboard.more', { n: pendingCount - requestsShown.length })
                        }}</Link>
                    </p>
                </template>
                <div v-else class="empty">
                    <Icon name="check" size="lg" />
                    <p style="margin: 8px 0 0">{{ $t('owner.dashboard.no_requests') }}</p>
                </div>
            </section>
        </div>

        <section class="card panel week7">
            <div class="sec-h" style="margin-bottom: 2px">
                <h2>{{ $t('owner.dashboard.chart') }}</h2>
                <div class="legend">
                    <span v-for="f in owner.fields" :key="f.id"><i :class="['fdot', dotOf(f.size)]" />{{ f.format }}</span>
                </div>
            </div>
            <p class="faint" style="margin: 0 0 12px; font-size: 12.5px">{{ $t('owner.dashboard.chart_sub') }}</p>
            <div class="wchart" role="img" :aria-label="$t('owner.dashboard.chart')">
                <div class="yax" aria-hidden="true"><span>100%</span><span>50%</span><span>0</span></div>
                <button
                    v-for="d in chartDays"
                    :key="d.date"
                    type="button"
                    :class="['wcol', { today: d.today }]"
                    :title="d.bars.map((x) => `${x.label}: ${x.p}%`).join(' · ')"
                    @click="router.visit(route('owner.schedule', { date: d.date }))"
                >
                    <span class="bars">
                        <i v-for="x in d.bars" :key="x.id" :class="dotOf(x.size)" :style="{ height: Math.max(2, x.p) + '%' }"
                            ><em>{{ x.p }}</em></i
                        >
                    </span>
                    <small>{{ d.top }}</small>
                    <b>{{ d.day }}</b>
                </button>
            </div>
        </section>
    </OwnerLayout>
</template>
