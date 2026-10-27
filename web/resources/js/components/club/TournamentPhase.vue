<script setup lang="ts">
// Where a tournament is, as a coloured badge (lists for players and owners, the tournament pages):
// registration open (green) · registration closed (grey) · draw made (orange) · on now (red, pulsing) · finished (gold).
export type Phase = 'registration' | 'closed' | 'drawn' | 'live' | 'done';
defineProps<{ phase: Phase }>();
</script>

<template>
    <span :class="['tphase', phase]" :data-phase="phase"><i v-if="phase === 'live'" class="d" />{{ $t('club.tournament.badge_' + phase) }}</span>
</template>

<style scoped>
.tphase { display: inline-flex; align-items: center; gap: 6px; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 800; white-space: nowrap; border: 1px solid transparent; }
.registration { color: #5ef0a8; background: rgba(94, 240, 168, 0.12); border-color: rgba(94, 240, 168, 0.3); }
.closed { color: #9aa69b; background: rgba(154, 166, 155, 0.12); border-color: rgba(154, 166, 155, 0.25); }
.drawn { color: #ff9f43; background: rgba(255, 159, 67, 0.12); border-color: rgba(255, 159, 67, 0.35); }
.live { color: #ff6b6b; background: rgba(255, 107, 107, 0.13); border-color: rgba(255, 107, 107, 0.4); }
.done { color: #ffd166; background: rgba(255, 209, 102, 0.12); border-color: rgba(255, 209, 102, 0.35); }
.d { width: 7px; height: 7px; border-radius: 50%; background: #ff6b6b; animation: tp 1.4s infinite; }
@keyframes tp { 50% { opacity: 0.3; } }
</style>
