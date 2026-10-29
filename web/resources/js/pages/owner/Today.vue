<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import { decisions, longDate, openForm, useOwner } from '@/composables/useOwner';
import { useShared } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';

// Today: four numbers of the day — the bookings, the requests waiting (a tap opens Requests), the trainings and how
// full the courts are (each court as its icon). What is on the tables now: Now; who comes later: Schedule.
const props = defineProps<{
    date: string;
    me: string;
    kpi: {
        today: number;
        todayAccepted: number;
        todayPending: number;
        pending: number;
        trainings: number;
        occupancy: Record<number, number>;
    };
}>();
const owner = useOwner();
// staff: new bookings start on Now
const staff = computed(() => owner.value.role === 'staff');
const { user } = useShared();
usePoll(30000, { only: ['kpi'] });
const first = computed(() => (props.me || '').split(' ')[0]);
// answered on this device but still inside the Undo window
const pendingCount = computed(() => Math.max(0, props.kpi.pending - Object.keys(decisions.hidden).length));
const avg = (m: Record<number, number>) => {
    const v = owner.value.fields.map((f) => m[f.id] ?? 0);
    return v.length ? Math.round(v.reduce((a, b) => a + b, 0) / v.length) : 0;
};
const level = (p: number) => (p >= 70 ? 'hi' : p >= 35 ? 'mid' : 'lo');
const occToday = computed(() => avg(props.kpi.occupancy));
</script>

<template>
    <OwnerLayout :title="$t('common.today')" :new-booking="staff ? undefined : {}">
        <div class="phead">
            <div>
                <div class="kicker">{{ longDate(date) }} · {{ owner.venue.name }}</div>
                <h1>{{ first ? $t('owner.dashboard.hello', { name: first }) : $t('common.today') }}</h1>
            </div>
            <div class="phead-r">
                <button v-if="!staff" type="button" class="btn new" data-test="new" @click="openForm({})">
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
            <Link class="card kpi" :href="route('owner.schedule')">
                <div class="lbl">
                    <Icon name="calendar" size="sm" /><span>{{ $t('common.bookings') }}</span>
                </div>
                <div class="val" data-test="kpi-today">{{ kpi.today }}</div>
                <div class="sub">{{ $t('owner.dashboard.kpi_booked_sub', { a: kpi.todayAccepted }) }}</div>
            </Link>
            <Link :class="['card kpi', { warn: pendingCount }]" :href="route('owner.requests')" data-test="kpi-requests">
                <div class="lbl">
                    <Icon name="msg" size="sm" /><span>{{ $t('common.requests') }}</span>
                </div>
                <div class="val">{{ pendingCount }}</div>
                <div class="sub">{{ $t(pendingCount ? 'owner.dashboard.kpi_requests_sub' : 'owner.dashboard.kpi_requests_none') }}</div>
            </Link>
            <Link class="card kpi" :href="route('owner.schedule')" data-test="kpi-trainings">
                <div class="lbl">
                    <span class="tico">🎓</span><span>{{ $t('owner.dashboard.kpi_trainings') }}</span>
                </div>
                <div class="val">{{ kpi.trainings }}</div>
                <div class="sub">{{ $t('owner.dashboard.kpi_trainings_sub') }}</div>
            </Link>
            <Link class="card kpi" :href="route('owner.schedule')" data-test="kpi-occupancy">
                <div class="lbl">
                    <Icon name="chart" size="sm" /><span>{{ $t('owner.dashboard.kpi_occupancy') }}</span>
                </div>
                <div class="val">{{ occToday }}%</div>
                <div class="occ" data-test="occ">
                    <span v-for="f in owner.fields" :key="f.id" :class="['oi', level(kpi.occupancy[f.id] ?? 0)]" :title="`${f.label}: ${kpi.occupancy[f.id] ?? 0}%`"
                        ><em :style="{ opacity: 0.4 + (kpi.occupancy[f.id] ?? 0) / 160 }">{{ f.format }}</em><small>{{ kpi.occupancy[f.id] ?? 0 }}%</small></span
                    >
                </div>
            </Link>
        </div>
    </OwnerLayout>
</template>

<style scoped>
a.kpi { text-decoration: none; color: inherit; }
a.kpi:hover { border-color: var(--line-2); }
.tico { font-size: 14px; }
</style>
