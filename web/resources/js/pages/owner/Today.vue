<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import {
    accept,
    askDecline,
    dotOf,
    dur,
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
import { Link, router, usePoll } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

// Today: KPIs, requests waiting (Accept / Decline), next games, occupancy of the next 7 days.
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
    requests: BookingRow[];
    next: BookingRow[];
    chart: { date: string; fields: Record<number, number> }[];
    nowHour: number;
}>();
const owner = useOwner();
usePoll(30000, { only: ['kpi', 'requests', 'next', 'chart', 'owner'] });

const fieldOf = (id: number) => owner.value.fields.find((f) => f.id === id);
const first = computed(() => (props.me || '').split(' ')[0]);
const short = (n: number) => (n >= 1000 ? `${Math.round(n / 100) / 10}k ֏` : amd(n));

// grouped bars: one pair (5×5, 11×11) per day
const W = 460;
const H = 210;
const L = 30;
const B = 34;
const T0 = 16;
const cw = (W - L) / 7;
const bw = Math.min(18, cw / 3.2);
const y = (p: number) => T0 + (H - B - T0) * (1 - p / 100);
const bars = computed(() =>
    props.chart.flatMap((d, i) => {
        const x0 = L + i * cw + cw / 2;
        return owner.value.fields.map((f, k) => {
            const p = d.fields[f.id] ?? 0;
            const h = Math.max(2, y(0) - y(p));
            const n = owner.value.fields.length;
            const x = x0 - (n * (bw + 1)) / 2 + k * (bw + 2);
            return {
                key: `${d.date}-${f.id}`,
                date: d.date,
                x,
                y: y(0) - h,
                h,
                fill: f.size === 'small' ? 'var(--c-small)' : 'var(--c-big)',
                title: `${longDate(d.date)} · ${f.label}: ${p}%`,
            };
        });
    }),
);
const labels = computed(() =>
    props.chart.map((d, i) => ({
        date: d.date,
        x: L + i * cw + cw / 2,
        top: i === 0 ? trans('owner.today') : fdate(d.date, { weekday: 'short' }),
        day: fdate(d.date, { day: 'numeric' }),
        today: i === 0,
    })),
);
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.today')">
        <div class="phead">
            <div>
                <div class="kicker">{{ longDate(date) }} · {{ owner.venue.name }}</div>
                <h1>{{ first ? $t('owner.today_page.hello', { name: first }) : $t('owner.nav.today') }}</h1>
            </div>
            <div class="phead-r">
                <Link class="btn ghost" :href="route('owner.schedule')"
                    ><Icon name="calendar" size="sm" />{{ $t('owner.today_page.open_schedule') }}</Link
                >
                <button type="button" class="btn" @click="openForm({})"><Icon name="plus" size="sm" />{{ $t('owner.new_booking') }}</button>
            </div>
        </div>

        <div class="kpis">
            <div class="card kpi">
                <div class="lbl"><Icon name="calendar" size="sm" />{{ $t('owner.today_page.k_today') }}</div>
                <div class="val" data-test="kpi-today">{{ kpi.today }}</div>
                <div class="sub">{{ $t('owner.today_page.k_today_sub', { a: kpi.todayAccepted, p: kpi.todayPending }) }}</div>
            </div>
            <div :class="['card kpi', { warn: kpi.pending }]">
                <div class="lbl"><Icon name="msg" size="sm" />{{ $t('owner.today_page.k_pending') }}</div>
                <div class="val">{{ kpi.pending }}</div>
                <div class="sub">{{ $t(kpi.pending ? 'owner.today_page.k_pending_sub' : 'owner.today_page.k_pending_none') }}</div>
            </div>
            <div class="card kpi">
                <div class="lbl"><Icon name="chart" size="sm" />{{ $t('owner.today_page.k_occ') }}</div>
                <div class="occ">
                    <div v-for="f in owner.fields" :key="f.id">
                        <span>{{ f.format }}</span
                        ><span :class="['bar', dotOf(f.size)]"><i :style="{ width: (kpi.occupancy[f.id] ?? 0) + '%' }" /></span
                        ><b>{{ kpi.occupancy[f.id] ?? 0 }}%</b>
                    </div>
                </div>
            </div>
            <div class="card kpi">
                <div class="lbl"><Icon name="wallet" size="sm" />{{ $t('owner.today_page.k_rev') }}</div>
                <div class="val">{{ short(kpi.revenueToday) }}</div>
                <div class="sub">
                    {{ $t('owner.today_page.k_rev_sub') }} <b style="white-space: nowrap; color: var(--muted)">{{ amd(kpi.revenueWeek) }}</b>
                </div>
            </div>
        </div>

        <div class="dash">
            <section class="card panel">
                <div class="sec-h">
                    <h2>
                        {{ $t('owner.today_page.requests') }} <span v-if="kpi.pending" class="pill warn">{{ kpi.pending }}</span>
                    </h2>
                    <Link v-if="kpi.pending" class="see-all" :href="route('owner.schedule', { view: 'list', status: 'pending' })"
                        >{{ $t('owner.see_all') }} <Icon name="arrow" size="sm"
                    /></Link>
                </div>
                <template v-if="requests.length">
                    <div v-for="b in requests" :key="b.id" class="req" data-test="request">
                        <div class="who" @click="openBooking(b.id)">
                            <b>{{ b.name }}</b
                            ><span v-if="b.team" class="muted"> · {{ b.team }}</span>
                            <div class="meta">
                                <span><i :class="['fdot', dotOf(fieldOf(b.field_id)?.size || 'small')]" />{{ fieldOf(b.field_id)?.label }}</span>
                                <span><Icon name="calendar" size="sm" />{{ relDay(b.date, date) }} · {{ hhmm(b.start) }}–{{ hhmm(b.end) }}</span>
                                <span><Icon name="wallet" size="sm" />{{ amd(b.price) }}</span>
                                <span v-if="b.sent_ago" class="faint">{{ $t('owner.today_page.sent', { ago: b.sent_ago }) }}</span>
                            </div>
                        </div>
                        <div class="acts">
                            <button type="button" class="btn big" data-test="accept" @click="accept(b.id)">
                                <Icon name="check" size="sm" />{{ $t('owner.a.accept') }}
                            </button>
                            <button type="button" class="btn danger big" data-test="decline" @click="askDecline(b.id)">
                                <Icon name="x" size="sm" />{{ $t('owner.a.decline') }}
                            </button>
                        </div>
                    </div>
                    <p v-if="kpi.pending > requests.length" style="margin: 12px 0 0">
                        <Link class="see-all" :href="route('owner.schedule', { view: 'list', status: 'pending' })">{{
                            $t('owner.today_page.more', { n: kpi.pending - requests.length })
                        }}</Link>
                    </p>
                </template>
                <div v-else class="empty">
                    <Icon name="check" size="lg" />
                    <p style="margin: 8px 0 0">{{ $t('owner.today_page.no_requests') }}</p>
                </div>
            </section>

            <div style="display: flex; flex-direction: column; gap: 22px; min-width: 0">
                <section class="card panel games">
                    <div class="sec-h">
                        <h2>{{ $t('owner.today_page.next') }}</h2>
                    </div>
                    <template v-if="next.length">
                        <div v-for="b in next" :key="b.id" class="g" @click="openBooking(b.id)">
                            <div class="t">
                                {{ hhmm(b.start) }}<small>{{ dur(b.minutes) }}</small>
                            </div>
                            <div style="min-width: 0">
                                <b style="display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ b.team || b.name }}</b>
                                <span class="muted" style="font-size: 12.5px; display: inline-flex; gap: 6px; align-items: center"
                                    ><i :class="['fdot', dotOf(fieldOf(b.field_id)?.size || 'small')]" />{{ fieldOf(b.field_id)?.label }}</span
                                >
                            </div>
                            <span :class="['pill', pillOf(b).cls]">{{ pillOf(b).label }}</span>
                        </div>
                    </template>
                    <p v-else class="muted" style="margin: 6px 0">{{ $t('owner.today_page.no_next') }}</p>
                </section>
                <section class="card panel">
                    <div class="sec-h" style="margin-bottom: 4px">
                        <h2>{{ $t('owner.today_page.chart') }}</h2>
                    </div>
                    <p class="faint" style="margin: 0 0 10px; font-size: 12.5px">{{ $t('owner.today_page.chart_sub') }}</p>
                    <div class="legend" style="margin-bottom: 8px">
                        <span v-for="f in owner.fields" :key="f.id"><i :class="['fdot', dotOf(f.size)]" />{{ f.label }}</span>
                    </div>
                    <div class="chart">
                        <svg :viewBox="`0 0 ${W} ${H}`" role="img" :aria-label="$t('owner.today_page.chart')">
                            <g class="gridl">
                                <template v-for="p in [0, 25, 50, 75, 100]" :key="p">
                                    <line :x1="L" :x2="W" :y1="y(p)" :y2="y(p)" />
                                    <text :x="L - 6" :y="y(p) + 4" text-anchor="end">{{ p }}</text>
                                </template>
                            </g>
                            <g
                                v-for="r in bars"
                                :key="r.key"
                                style="cursor: pointer"
                                @click="router.visit(route('owner.schedule', { date: r.date }))"
                            >
                                <rect class="m" :x="r.x" :y="r.y" :width="bw" :height="r.h" rx="4" :fill="r.fill">
                                    <title>{{ r.title }}</title>
                                </rect>
                            </g>
                            <template v-for="l in labels" :key="l.date">
                                <text :x="l.x" :y="H - 16" text-anchor="middle" :style="l.today ? 'fill: var(--accent)' : ''">{{ l.top }}</text>
                                <text :x="l.x" :y="H - 3" text-anchor="middle" style="font-weight: 600; fill: var(--faint)">{{ l.day }}</text>
                            </template>
                        </svg>
                    </div>
                </section>
            </div>
        </div>
    </OwnerLayout>
</template>
