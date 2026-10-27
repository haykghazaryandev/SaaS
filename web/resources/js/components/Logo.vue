<script setup lang="ts">
defineProps<{ sub?: string }>();
</script>

<template>
    <span class="logo">
        <span class="logo-mark">
            <i class="logo-glyph" aria-hidden="true" />
        </span>
        <span>ZoneSpot<small v-if="sub" class="logo-sub">{{ sub }}</small></span>
    </span>
</template>

<style scoped>
.logo-sub { display: block; font-family: var(--mono); font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--accent); margin-top: -2px; }
</style>
