<script setup lang="ts">
import { computed } from 'vue';

// Booking status as a coloured pill (docs/ux-review G1): waiting = amber, confirmed = green, owner-made = violet,
// cancelled / expired / declined / played = grey. Uses the shared .status-pill classes from bookball.css.
const props = defineProps<{ status: string }>();
const LOOK: Record<string, string> = {
    pending: 'pending',
    accepted: 'confirmed',
    manual: 'manual',
    declined: 'off',
    cancelled: 'off',
    expired: 'off',
    completed: 'off',
    no_show: 'off',
};
const look = computed(() => LOOK[props.status] ?? 'off');
</script>

<template>
    <span :class="['status-pill', look]">{{ $t(`player.status.${status}`) }}</span>
</template>
