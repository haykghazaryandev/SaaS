<script setup lang="ts">
import Flash from '@/components/Flash.vue';
import LangSwitch from '@/components/LangSwitch.vue';
import Logo from '@/components/Logo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="auth-wrap">
        <div class="auth-top">
            <Link :href="route('home')"><Logo /></Link>
            <LangSwitch />
        </div>
        <div class="card auth-card"><slot /></div>
    </div>
    <Flash />
</template>

<style scoped>
.auth-wrap { min-height: 100vh; display: flex; flex-direction: column; align-items: center; padding: calc(20px + var(--sat)) 16px calc(40px + var(--sab)); }
.auth-top { width: min(440px, 100%); display: flex; justify-content: space-between; align-items: center; margin-bottom: 36px; }
.auth-card { width: min(440px, 100%); padding: 26px; }
</style>
