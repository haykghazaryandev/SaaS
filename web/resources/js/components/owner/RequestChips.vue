<script setup lang="ts">
import PlayerRecord from '@/components/owner/PlayerRecord.vue';
import type { BookingRow } from '@/composables/useOwner';

// A request at a glance, on one line: a training (its own colour), the order among requests for the same time
// (#1 = the best choice), the player's rating, their record here (didn't come, late… or reliable / new).
defineProps<{ b: BookingRow }>();
</script>

<template>
    <span class="rchips">
        <span v-if="b.purpose === 'training'" class="tchip" data-test="training">🎓 {{ $t('club.training') }}</span>
        <span v-if="b.rank === 1" class="rc best" data-test="rank">★ {{ $t('owner.requests.rank_best') }}</span>
        <span v-else-if="b.rank" class="rc" data-test="rank">{{ $t('owner.requests.rank_n', { n: b.rank }) }}</span>
        <span v-if="b.rating != null" class="rc rt">{{ b.rating }}</span>
        <PlayerRecord :history="b.history" />
    </span>
</template>

<style scoped>
.rchips { display: inline-flex; flex-wrap: wrap; gap: 5px; vertical-align: middle; }
.rc { font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 999px; background: var(--surface-2); color: var(--muted); border: 1px solid var(--line); white-space: nowrap; }
.rc.best { background: var(--free-soft); color: var(--free); border-color: transparent; }
.rc.rt { font-family: var(--mono); font-weight: 700; background: rgba(200, 255, 61, 0.12); color: var(--accent); border-color: transparent; }
</style>
