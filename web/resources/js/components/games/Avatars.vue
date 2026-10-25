<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';

// A row of overlapping avatars (the shared Avatar, G3), "+N" for the rest.
defineProps<{ people: { id?: number; initials: string; name: string | null; avatar?: string | null }[]; more?: number }>();
</script>

<template>
    <div class="avs">
        <span v-for="(p, i) in people" :key="i" class="av" :style="{ zIndex: 10 - i }"><Avatar :id="p.id ?? null" :name="p.name" :src="p.avatar" size="sm" /></span>
        <span v-if="more && more > 0" class="av more">+{{ more }}</span>
    </div>
</template>

<style scoped>
.avs { display: flex; align-items: center; }
.av { display: inline-grid; border-radius: 50%; box-shadow: 0 0 0 2px var(--surface); margin-left: -8px; position: relative; }
.av:first-child { margin-left: 0; }
.av.more { width: 30px; height: 30px; place-items: center; background: var(--surface-3); color: var(--muted); font-size: 10.5px; font-weight: 800; }
</style>
