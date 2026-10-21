<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import { computed } from 'vue';

// Kept for older callers: renders the shared Avatar (G3) at the nearest size.
const props = defineProps<{ name: string | null; id: number; size?: number }>();
const s = computed(() => {
    const px = props.size ?? 44;
    return px >= 72 ? 'xl' : px >= 50 ? 'lg' : px >= 36 ? 'md' : px >= 26 ? 'sm' : 'xs';
});
</script>

<template>
    <Avatar :id="id" :name="name && name !== '—' ? name : null" :size="s" />
</template>
