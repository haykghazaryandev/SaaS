<script setup lang="ts">
// Grey shapes where a page's content will be, while it loads (useNavLoading): a title, a row of chips, cards.
defineProps<{ cards?: number }>();
</script>

<template>
    <div class="pskel" aria-busy="true" :aria-label="$t('common.loading')" data-test="page-skeleton">
        <div class="sk sk-kick" />
        <div class="sk sk-title" />
        <div class="sk-chips"><div v-for="i in 4" :key="i" class="sk sk-chip" /></div>
        <div v-for="i in cards ?? 3" :key="i" class="sk sk-card" />
    </div>
</template>

<style scoped>
.pskel { display: flex; flex-direction: column; gap: 12px; padding-top: 4px; }
.sk { border-radius: 14px; background: linear-gradient(90deg, var(--surface-2) 0%, var(--surface-3) 50%, var(--surface-2) 100%); background-size: 200% 100%; animation: sk-shine 1.2s ease-in-out infinite; }
.sk-kick { height: 12px; width: 30%; border-radius: 6px; }
.sk-title { height: 30px; width: 55%; border-radius: 10px; }
.sk-chips { display: flex; gap: 8px; }
.sk-chip { height: 34px; flex: 1; max-width: 90px; border-radius: 999px; }
.sk-card { height: 120px; border-radius: 20px; }
@keyframes sk-shine { from { background-position: 200% 0; } to { background-position: -200% 0; } }
@media (prefers-reduced-motion: reduce) { .sk { animation: none; } }
</style>
