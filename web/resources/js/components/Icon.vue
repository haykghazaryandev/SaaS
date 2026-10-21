<script setup lang="ts">
import { ICONS } from '@/lib/icons';

defineProps<{ name: string; size?: 'sm' | 'lg' }>();
</script>

<template>
    <svg
        :class="['ico', size]"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        v-html="ICONS[name] || ''"
    />
</template>
