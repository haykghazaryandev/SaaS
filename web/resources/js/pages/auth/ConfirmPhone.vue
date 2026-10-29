<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

// "Confirm your phone": an account from email or Google has no confirmed phone. Booking (and games, tournaments, a
// club card) needs one — the place calls it about a booking. Telegram confirms it: the bot takes the number of that
// Telegram account. "Later": back to where they were (everything else stays open).
const props = defineProps<{ back: string | null }>();
const done = () => router.visit(props.back || route('home'));
</script>

<template>
    <AuthLayout>
        <Head :title="$t('auth.confirm.title')" />
        <h1 style="font-size: 26px">{{ $t('auth.confirm.title') }}</h1>
        <p class="muted" style="margin: 8px 0 14px">{{ $t('auth.confirm.why') }}</p>
        <div class="warnbox"><Icon name="alert" size="sm" />{{ $t('auth.confirm.no_booking') }}</div>
        <TelegramConnect compact @linked="done" />
        <Link class="btn ghost block" style="margin-top: 14px" :href="back || route('home')" data-test="confirm-later">{{ $t('auth.confirm.later') }}</Link>
    </AuthLayout>
</template>

<style scoped>
.warnbox { display: flex; gap: 8px; align-items: flex-start; padding: 10px 12px; border-radius: 12px; background: var(--warn-soft); color: var(--warn); font-weight: 700; font-size: 13.5px; margin-bottom: 14px; }
</style>
