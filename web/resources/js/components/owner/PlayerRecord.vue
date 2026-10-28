<script setup lang="ts">
// A player's record at this place, next to a request: didn't come, cancelled late, came late (warnings first), else
// how often they played here ("always came"), or "new here". Only this place's bookings count.
export interface Record {
    played: number;
    no_shows: number;
    late_cancels: number;
    lates: number;
}
const props = defineProps<{ history: Record | null | undefined }>();
const bad = () => !!props.history && props.history.no_shows + props.history.late_cancels + props.history.lates > 0;
</script>

<template>
    <span v-if="history" class="prec" data-test="player-record">
        <span v-if="history.no_shows" class="pr hot">⚠ {{ $t('owner.record.no_shows', { n: history.no_shows }) }}</span>
        <span v-if="history.late_cancels" class="pr warn">{{ $t('owner.record.late_cancels', { n: history.late_cancels }) }}</span>
        <span v-if="history.lates" class="pr warn">{{ $t('owner.record.lates', { n: history.lates }) }}</span>
        <span v-if="!bad() && history.played" class="pr ok">✓ {{ $t('owner.record.good', { n: history.played }) }}</span>
        <span v-else-if="history.played" class="pr">{{ $t('owner.record.played', { n: history.played }) }}</span>
        <span v-else-if="!bad()" class="pr">{{ $t('owner.record.new') }}</span>
    </span>
</template>

<style scoped>
.prec { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.pr { font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 999px; background: var(--surface-3); color: var(--muted); white-space: nowrap; }
.pr.hot { background: var(--hot-soft); color: var(--hot); }
.pr.warn { background: var(--warn-soft); color: var(--warn); }
.pr.ok { background: var(--free-soft); color: var(--free); }
</style>
