<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { computed } from 'vue';

// Booking status as a coloured pill: pending = waiting for the place, accepted, declined, …
const props = defineProps<{ status: string }>();
const LOOK: Record<string, [string, string]> = {
    pending: ['warn', 'clock'],
    accepted: ['green', 'check'],
    declined: ['hot', 'x'],
    cancelled: ['', 'x'],
    expired: ['', 'clock'],
    completed: ['', 'check'],
    no_show: ['hot', 'x'],
};
const look = computed(() => LOOK[props.status] ?? ['', 'clock']);
</script>

<template>
    <span :class="['pill', look[0]]"><Icon :name="look[1]" size="sm" />{{ $t(`player.status.${status}`) }}</span>
</template>
