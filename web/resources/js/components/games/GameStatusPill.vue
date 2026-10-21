<script setup lang="ts">
import { STATUS_LOOK } from '@/composables/useGames';
import { computed } from 'vue';

// Game status as a status pill (G1 colours): forming = waiting, open/full/booked = going on, played/cancelled/archived = over.
const props = defineProps<{ status: string }>();
const look = computed(() => STATUS_LOOK[props.status] ?? 'off');
</script>

<template>
    <span :class="['status-pill', look]">{{ $t(`games.status.${status}`) }}</span>
</template>
