<script setup lang="ts">
defineProps<{ sub?: string }>();
</script>

<template>
    <span class="logo">
        <span class="logo-mark">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="8.5" /><path d="M12 3.5v17M3.5 12h17" /></svg>
        </span>
        <span>Bookball<small v-if="sub" class="logo-sub">{{ sub }}</small></span>
    </span>
</template>

<style scoped>
.logo-sub { display: block; font-family: var(--mono); font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--accent); margin-top: -2px; }
</style>
