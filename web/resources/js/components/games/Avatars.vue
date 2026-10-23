<script setup lang="ts">
// A row of overlapping initials, "+N" for the rest.
defineProps<{ people: { initials: string; name: string | null }[]; more?: number }>();
</script>

<template>
    <div class="avs">
        <span v-for="(p, i) in people" :key="i" class="av" :title="p.name ?? ''" :style="{ zIndex: 10 - i }">{{ p.initials }}</span>
        <span v-if="more && more > 0" class="av more">+{{ more }}</span>
    </div>
</template>

<style scoped>
.avs { display: flex; align-items: center; }
.av { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; font-weight: 800; background: var(--surface-3); border: 2px solid var(--surface); margin-left: -8px; position: relative; color: var(--ink); }
.av:first-child { margin-left: 0; background: var(--grad); color: var(--on-accent); }
.av.more { background: var(--bg-2); color: var(--muted); font-size: 10.5px; }
</style>
