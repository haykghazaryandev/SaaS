<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import { decisions, fdate, useOwner } from '@/composables/useOwner';
import { trans } from '@/lib/i18n';
import { useShared } from '@/composables/useShared';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
// trainings: only places that give them (admin → place → features)
const hasTrainings = computed(() => (owner.value.features ?? []).includes('trainings'));
// the Telegram reminder: one line; × hides it for a week on this device
const TG_KEY = 'zs-tg-banner-off';
const tgHidden = ref(false);
try {
    tgHidden.value = Number(localStorage.getItem(TG_KEY) ?? 0) > Date.now() - 7 * 864e5;
} catch {
    /* no storage: show it */
}
function hideTg() {
    tgHidden.value = true;
    try {
        localStorage.setItem(TG_KEY, String(Date.now()));
    } catch {
        /* fine: hidden until the page loads again */
    }
}
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
        <div v-if="user && !user.telegram && !tgHidden" class="tg-banner tgline" data-test="tg-banner">
            <Icon name="send" />
            <div class="tgb-t">
                <b>{{ $t('owner.dashboard.telegram_title') }}</b>
                <small>{{ $t('owner.dashboard.telegram_sub') }}</small>
            </div>
            <span class="tg-full"><TelegramConnect compact /></span>
            <!-- phones: a small button to the profile, where Telegram is connected -->
            <Link class="btn sm tg-short" :href="route('me.profile')">{{ $t('owner.dashboard.telegram_go') }}</Link>
            <button type="button" class="tgx" :aria-label="$t('common.close')" data-test="tg-banner-x" @click="hideTg"><Icon name="x" size="sm" /></button>
        </div>

        <div :class="['kpis', 'tk', { n3: !hasTrainings }]">
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
            <Link v-if="hasTrainings" class="card kpi" :href="route('owner.schedule', kpi.date === today ? {} : { date: kpi.date })" preserve-scroll data-test="kpi-trainings">
                <div class="lbl">
                    <span class="tico">🎓</span><span>{{ $t('owner.dashboard.kpi_trainings') }}</span>
                </div>
                <div class="val">{{ kpi.trainings }}</div>
                <div class="sub">{{ dayLabel }}</div>
            </Link>
            <Link class="card kpi" :href="route('owner.schedule', kpi.date === today ? {} : { date: kpi.date })" preserve-scroll data-test="kpi-occupancy">
                <div class="lbl">
                    <Icon name="chart" size="sm" /><span class="lg">{{ $t('owner.dashboard.kpi_occupancy') }}</span
                    ><span class="sh">{{ $t('owner.dashboard.kpi_occupancy_short') }}</span>
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
.tgline { padding: 8px 10px 8px 14px; gap: 10px; }
.tgline .tgb-t b { font-size: 14px; }
.tgx { flex: none; width: 30px; height: 30px; border-radius: 50%; border: 0; background: transparent; color: inherit; display: grid; place-items: center; cursor: pointer; opacity: 0.8; }
.tgx:hover { opacity: 1; background: rgba(255, 255, 255, 0.06); }
/* phones: the numbers as small two-column rows (name left, number right), so the list starts high on the screen and
   long Armenian names are not cut */
@media (max-width: 560px) {
    .kpis.tk.tk, .kpis.tk.tk.n3 { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }
    .kpis.tk .kpi { flex-direction: row; align-items: center; justify-content: space-between; padding: 9px 12px; gap: 6px; border-radius: 14px; }
    .kpis.tk .kpi .lbl { font-size: 11.5px; gap: 5px; min-width: 0; }
    .kpis.tk .kpi .lbl :deep(svg), .kpis.tk .tico { display: none; }
    .kpis.tk .kpi .val { flex: none; }
    .kpis.tk .kpi .val { font-size: 20px; }
    .kpis.tk .kpi .sub, .kpis.tk .occ { display: none; }
    .tgline { flex-wrap: nowrap; }
    .tgline .tgb-t small, .tgline .tg-full { display: none; }
    .tgline .tg-short { display: inline-flex; }
}
.tg-short { display: none; flex: none; }
.lbl .sh { display: none; }
@media (max-width: 560px) {
    .lbl .lg { display: none; }
    .lbl .sh { display: inline; }
}
</style>
