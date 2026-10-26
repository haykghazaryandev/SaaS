<script setup lang="ts">
import { amd } from '@/composables/useShared';
import { computed } from 'vue';

// A card's cashback level: the % it gets now, what it has paid at the place, how far the next level is.
// It moves up by itself (Cards::level); the levels are the place's (owner → Settings).
export interface Level {
    pct: number;
    spent: number;
    next: { from: number; pct: number; left: number } | null;
}
const props = defineProps<{ level: Level; tiers?: { from: number; pct: number }[] }>();
const progress = computed(() => (props.level.next ? Math.min(100, Math.round((props.level.spent / props.level.next.from) * 100)) : 100));
</script>

<template>
    <div class="cb-level">
        <div class="cb-top">
            <b :class="{ on: level.pct > 0 }">{{ $t('club.cashback.now', { p: level.pct }) }}</b>
            <small class="faint">{{ $t('club.cashback.paid', { sum: amd(level.spent) }) }}</small>
        </div>
        <template v-if="level.next">
            <div class="cb-bar"><i :style="{ width: progress + '%' }" /></div>
            <small class="faint">{{ $t('club.cashback.next', { p: level.next.pct, from: amd(level.next.from), left: amd(level.next.left) }) }}</small>
        </template>
        <details v-if="tiers && tiers.length" class="cb-how">
            <summary class="faint">{{ $t('club.cashback.how') }}</summary>
            <ul>
                <li v-for="t in tiers" :key="t.from" :class="{ on: t.pct === level.pct && level.spent >= t.from }">
                    {{ $t('club.cashback.row', { from: amd(t.from), p: t.pct }) }}
                </li>
            </ul>
        </details>
    </div>
</template>

<style scoped>
.cb-level { display: grid; gap: 6px; }
.cb-top { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; }
.cb-top b { font-size: 15px; }
.cb-top b.on { color: var(--free, #16a34a); }
.cb-bar { height: 6px; border-radius: 99px; background: var(--line-2, rgba(0, 0, 0, 0.1)); overflow: hidden; }
.cb-bar i { display: block; height: 100%; border-radius: inherit; background: var(--free, #16a34a); }
.cb-how summary { cursor: pointer; font-size: 12.5px; }
.cb-how ul { margin: 6px 0 0; padding-left: 18px; font-size: 13px; }
.cb-how li.on { font-weight: 700; }
</style>
