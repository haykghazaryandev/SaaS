<script setup lang="ts">
import { computed } from 'vue';

// The one player-count bar of a game: "4 spots left" (or "2 more to open" while forming) and in/total.
// While forming, a tick marks how many players open the game.
const props = withDefaults(defineProps<{ inCount: number; total: number; minToOpen: number; status: string; big?: boolean }>(), { big: false });
const forming = computed(() => props.status === 'forming');
const left = computed(() => Math.max(0, props.total - props.inCount));
const toOpen = computed(() => Math.max(0, props.minToOpen - props.inCount));
const over = computed(() => ['played', 'cancelled', 'archived'].includes(props.status));
const pct = (n: number) => Math.min(100, Math.round((n / Math.max(1, props.total)) * 100));
</script>

<template>
    <div :class="['gprog', { big, forming, over }]">
        <div class="lbl">
            <b v-if="over" class="lead">{{ $t('games.card.players', { in: String(inCount), total: String(total) }) }}</b>
            <b v-else-if="forming" class="lead">{{ $tChoice('games.card.moreToOpen', toOpen, { n: String(toOpen) }) }}</b>
            <b v-else-if="left" class="lead">{{ $tChoice('games.card.spotsLeft', left, { n: String(left) }) }}</b>
            <b v-else class="lead">{{ $t('games.card.isFull') }}</b>
            <span v-if="!over" class="cnt">{{ inCount }}/{{ total }}</span>
        </div>
        <div class="bar" role="progressbar" :aria-valuenow="inCount" :aria-valuemax="total" aria-valuemin="0">
            <i :style="{ width: pct(inCount) + '%' }" />
            <s v-if="forming" :style="{ left: pct(minToOpen) + '%' }" :title="$t('games.card.toOpen', { in: String(inCount), min: String(minToOpen) })" />
        </div>
    </div>
</template>

<style scoped>
.gprog { display: flex; flex-direction: column; gap: 7px; }
.lbl { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.lead { font-size: 15px; font-weight: 800; color: var(--accent); }
.big .lead { font-size: 18px; }
.forming .lead { color: var(--st-pending); }
.over .lead { color: var(--muted); font-size: 13.5px; }
.cnt { font-size: 13px; font-weight: 700; color: var(--muted); font-variant-numeric: tabular-nums; }
.bar { position: relative; overflow: visible; }
.bar i { border-radius: 99px; }
.forming .bar i { background: var(--st-pending); }
.over .bar i { background: var(--st-off); }
.bar s { position: absolute; top: -3px; bottom: -3px; width: 2px; margin-left: -1px; background: var(--ink); border-radius: 2px; opacity: .7; }
</style>
