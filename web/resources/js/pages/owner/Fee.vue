<script setup lang="ts">
import { useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { computed } from 'vue';

// ZoneSpot fee (the owner): just ZoneSpot's % and what it is taken from — bookings, trainings, tournament fees —
// with an example; never the shop or card top-ups. No history here (the place's money is on Money).
const props = defineProps<{ pct: number }>();
const owner = useOwner();
const pctText = computed(() => (Number.isInteger(props.pct) ? String(props.pct) : props.pct.toFixed(1)));
const example = computed(() => Math.round((10000 * props.pct) / 100));
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.fee')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.fee') }}</h1>
            </div>
        </div>
        <section class="card panel feecard" data-test="fee">
            <p class="lead">{{ $t('owner.fee.intro') }}</p>
            <div class="feerows">
                <div v-for="k in ['booking', 'training', 'tournament']" :key="k" class="frow">
                    <span>{{ $t('owner.fee.kind_' + k) }}</span><b class="pctb">{{ pctText }}%</b>
                </div>
                <div class="frow off">
                    <span>{{ $t('club.kind.shop') }} · {{ $t('club.kind.topup') }}</span><b>{{ $t('owner.fee.not_taken') }}</b>
                </div>
            </div>
            <p class="ex">{{ $t('owner.fee.example', { a: amd(10000), f: amd(example) }) }}</p>
            <p class="faint" style="font-size: 12.5px; margin: 8px 0 0">{{ $t('owner.fee.who_sets') }}</p>
        </section>
    </OwnerLayout>
</template>

<style scoped>
.feecard { max-width: 620px; border-color: rgba(200, 255, 61, 0.3); background: linear-gradient(135deg, rgba(200, 255, 61, 0.07), transparent 55%), var(--surface); }
.lead { margin: 0 0 12px; font-size: 15px; }
.feerows { display: flex; flex-direction: column; border-top: 1px solid var(--line); }
.frow { display: flex; justify-content: space-between; align-items: center; padding: 12px 2px; border-bottom: 1px solid var(--line); font-weight: 700; }
.pctb { font: 700 15px var(--mono); padding: 5px 12px; border-radius: 999px; background: rgba(200, 255, 61, 0.14); color: var(--accent); }
.frow.off { color: var(--muted); }
.frow.off b { font-size: 13px; color: var(--free); }
.ex { margin: 14px 0 0; padding: 10px 12px; border-radius: 12px; background: var(--surface-2); font-size: 14px; }
</style>
