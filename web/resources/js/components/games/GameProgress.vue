<script setup lang="ts">
import { computed } from 'vue';

// Players in the game: towards opening (forming) or towards full (open and later).
const props = defineProps<{ inCount: number; total: number; minToOpen: number; status: string }>();
const forming = computed(() => props.status === 'forming');
const goal = computed(() => (forming.value ? props.minToOpen : props.total));
const pct = computed(() => Math.min(100, Math.round((props.inCount / Math.max(1, goal.value)) * 100)));
</script>

<template>
    <div class="gprog">
        <div class="row between lbl">
            <span v-if="forming" class="warn">{{ $t('games.card.toOpen', { in: String(inCount), min: String(minToOpen) }) }}</span>
            <span v-else>{{ $t('games.card.players', { in: String(inCount), total: String(total) }) }}</span>
            <span v-if="!forming && inCount < total" class="faint">{{ $t('games.card.left', { n: String(total - inCount) }) }}</span>
        </div>
        <div :class="['bar', { f: forming }]" role="progressbar" :aria-valuenow="inCount" :aria-valuemax="goal" aria-valuemin="0"><i :style="{ width: pct + '%' }" /></div>
    </div>
</template>

<style scoped>
.gprog { display: flex; flex-direction: column; gap: 6px; }
.lbl { font-size: 13px; font-weight: 700; gap: 8px; }
.lbl .warn { color: var(--warn); }
.bar.f i { background: linear-gradient(90deg, #ffb547, #ffd27a); }
</style>
