<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { STATUS_LOOK } from '@/composables/useGames';
import { computed } from 'vue';

// Game status as a coloured pill: forming, open, full, booked, played, cancelled, archived.
const props = defineProps<{ status: string }>();
const look = computed(() => STATUS_LOOK[props.status] ?? ['', 'clock']);
</script>

<template>
    <span :class="['pill', look[0]]"><Icon :name="look[1]" size="sm" />{{ $t(`games.status.${status}`) }}</span>
</template>
