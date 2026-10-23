<script setup lang="ts">
import { computed } from 'vue';

// Round avatar with the player's initials; the colour is picked from the id so it stays the same everywhere.
const props = defineProps<{ name: string | null; id: number; size?: number }>();
const initials = computed(() =>
    (props.name && props.name !== '—' ? props.name : '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase(),
);
const HUES = [78, 150, 200, 32, 280, 340, 120, 180];
const hue = computed(() => HUES[props.id % HUES.length]);
</script>

<template>
    <span
        class="pav"
        aria-hidden="true"
        :style="{ width: (size ?? 44) + 'px', height: (size ?? 44) + 'px', fontSize: Math.round((size ?? 44) * 0.36) + 'px', background: `hsl(${hue} 45% 22%)`, color: `hsl(${hue} 90% 78%)` }"
        >{{ initials }}</span
    >
</template>

<style scoped>
.pav { flex: none; border-radius: 30%; display: grid; place-items: center; font-family: var(--display); font-weight: 600; border: 1px solid rgba(255, 255, 255, 0.06); }
</style>
