<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { Link } from '@inertiajs/vue3';

// An empty list, the same everywhere: an icon, what it means, and the next step (a link or a button: the slot).
defineProps<{ icon: string; title: string; text?: string; href?: string; action?: string; compact?: boolean }>();
</script>

<template>
    <div :class="['empty-state', 'es', { compact }]" data-test="empty">
        <span class="es-ico"><Icon :name="icon" /></span>
        <h3>{{ title }}</h3>
        <p v-if="text">{{ text }}</p>
        <Link v-if="href && action" class="btn sm" :href="href"><Icon name="plus" size="sm" />{{ action }}</Link>
        <slot />
    </div>
</template>

<style scoped>
.es { background: var(--surface); }
.es h3 { margin: 0; color: var(--ink); font-size: 16px; }
.es p { margin: 0; font-size: 13.5px; max-width: 360px; }
.es .es-ico { background: color-mix(in srgb, var(--accent) 12%, transparent); color: var(--accent); }
.es.compact { padding: 20px 14px; }
</style>
