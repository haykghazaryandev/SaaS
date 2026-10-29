<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import { decisions, fdate, useOwner } from '@/composables/useOwner';
import { trans } from '@/lib/i18n';
import { useShared } from '@/composables/useShared';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// The four numbers of the day shown on the schedule (today, or the day picked): its bookings, the requests waiting
// for it (a tap opens Requests), its trainings and how full the courts are (each court as its icon). And a reminder to
// connect Telegram.
export interface TodayNumbers {
    date: string;
    today: number;
    pending: number;
    trainings: number;
    occupancy: Record<number, number>;
}
const props = defineProps<{ kpi: TodayNumbers; today: string }>();
// "today" / "Sat 31 Oct" under each number
const dayLabel = computed(() => (props.kpi.date === props.today ? trans('common.today') : fdate(props.kpi.date, { weekday: 'short', day: 'numeric', month: 'short' })));
const owner = useOwner();
const { user } = useShared();
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
        <div v-if="user && !user.telegram" class="tg-banner" data-test="tg-banner">
            <Icon name="send" />
            <div class="tgb-t">
                <b>{{ $t('owner.dashboard.telegram_title') }}</b>
                <small>{{ $t('owner.dashboard.telegram_sub') }}</small>
            </div>
            <TelegramConnect compact />
        </div>

        <div class="kpis">
            <Link class="card kpi" :href="route('owner.schedule', kpi.date === today ? {} : { date: kpi.date })" preserve-scroll>
                <div class="lbl">
                    <Icon name="calendar" size="sm" /><span>{{ $t('common.bookings') }}</span>
                </div>
                <div class="val" data-test="kpi-today">{{ kpi.today }}</div>
                <div class="sub">{{ dayLabel }}</div>
            </Link>
            <Link :class="['card kpi', { warn: pendingCount }]" :href="route('owner.requests')" data-test="kpi-requests">
                <div class="lbl">
                    <Icon name="msg" size="sm" /><span>{{ $t('common.requests') }}</span>
                </div>
                <div class="val">{{ pendingCount }}</div>
                <div class="sub">{{ $t(pendingCount ? 'owner.dashboard.kpi_requests_sub' : 'owner.dashboard.kpi_requests_none') }}</div>
            </Link>
            <Link class="card kpi" :href="route('owner.schedule', kpi.date === today ? {} : { date: kpi.date })" preserve-scroll data-test="kpi-trainings">
                <div class="lbl">
                    <span class="tico">🎓</span><span>{{ $t('owner.dashboard.kpi_trainings') }}</span>
                </div>
                <div class="val">{{ kpi.trainings }}</div>
                <div class="sub">{{ dayLabel }}</div>
            </Link>
            <Link class="card kpi" :href="route('owner.schedule', kpi.date === today ? {} : { date: kpi.date })" preserve-scroll data-test="kpi-occupancy">
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
</template>

<style scoped>
a.kpi { text-decoration: none; color: inherit; }
a.kpi:hover { border-color: var(--line-2); }
.tico { font-size: 14px; }
.kpis { margin-bottom: 14px; }
</style>
