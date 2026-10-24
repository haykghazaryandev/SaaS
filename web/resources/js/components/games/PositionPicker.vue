<script setup lang="ts">
// Optional position in a game: one chip per position (tap the chosen one again to clear) + "No position".
defineProps<{ positions: string[]; label?: string }>();
const model = defineModel<string | null>({ required: true });
</script>

<template>
    <div class="pospick">
        <span v-if="label" class="lbl">{{ label }}</span>
        <div class="chips" role="group" :aria-label="label ?? $t('games.pos.title')">
            <button v-for="p in positions" :key="p" type="button" class="chip" :aria-pressed="model === p" :title="$t(`profile.positions.${p}`)" @click="model = model === p ? null : p">
                {{ $t(`profile.positions.${p}`) }}
            </button>
            <button type="button" class="chip" :aria-pressed="model === null" @click="model = null">{{ $t('games.pos.none') }}</button>
        </div>
    </div>
</template>

<style scoped>
.pospick { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.lbl { font-size: 13px; font-weight: 700; color: var(--muted); }
.chips { flex-wrap: wrap; }
.chip { min-height: 32px; padding: 6px 12px; font-size: 13px; }
</style>
