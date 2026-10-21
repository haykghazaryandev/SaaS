<script setup lang="ts">
import { computed, ref, watch } from 'vue';

// One avatar everywhere (docs/ux-review G3): the person's photo when they uploaded one,
// otherwise a circle with initials on a color derived from the user id.
// <Avatar :id="u.id" :name="u.name" :src="u.avatar" size="xs|sm|md|lg|xl" />
const props = withDefaults(defineProps<{ id?: number | null; name?: string | null; src?: string | null; size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl' }>(), {
    id: null,
    name: null,
    src: null,
    size: 'md',
});

// calm hues that work on the dark theme
const HUES = [152, 78, 200, 265, 32, 340, 180, 110, 220, 12];
const initials = computed(() => {
    const parts = (props.name ?? '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return ((parts[0][0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
});
const hue = computed(() => {
    const seed = props.id ?? [...(props.name ?? '?')].reduce((a, c) => a + c.charCodeAt(0), 0);
    return HUES[Math.abs(seed) % HUES.length];
});
// a broken photo link falls back to the initials
const failed = ref(false);
watch(() => props.src, () => (failed.value = false));
</script>

<template>
    <span :class="['avatar-c', size]" :style="{ '--h': hue }" :title="name ?? undefined" aria-hidden="true">
        <img v-if="src && !failed" :src="src" alt="" loading="lazy" @error="failed = true" />
        <template v-else>{{ initials }}</template>
    </span>
</template>

<style scoped>
/* display / alignment are set firmly: parent rows often style their <span>s as flex */
.avatar-c {
    display: inline-flex !important; align-items: center !important; justify-content: center !important; text-align: center; line-height: 1;
    flex: none; border-radius: 50%; overflow: hidden; font-weight: 800; letter-spacing: 0; user-select: none; padding: 0 !important; gap: 0 !important;
    background: hsl(var(--h) 45% 22%); color: hsl(var(--h) 85% 78%); box-shadow: inset 0 0 0 1px hsl(var(--h) 60% 40% / 0.5);
}
.avatar-c img { width: 100%; height: 100%; object-fit: cover; display: block; }
.xs { width: 22px; height: 22px; font-size: 9px; }
.sm { width: 30px; height: 30px; font-size: 11.5px; }
.md { width: 40px; height: 40px; font-size: 14px; }
.lg { width: 56px; height: 56px; font-size: 19px; }
.xl { width: 84px; height: 84px; font-size: 28px; }
</style>
