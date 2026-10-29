<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

// first login: the player tells us their name (and a username if they like). An account without a confirmed phone
// (email, Google) is then asked to confirm it with Telegram — needed to book, everything else is open.
const props = defineProps<{ name: string | null; username?: string | null }>();
const form = useForm({ name: props.name ?? '', username: props.username ?? '' });
</script>

<template>
    <AuthLayout>
        <Head :title="$t('common.welcome_to_bookball')" />
        <h1 style="font-size: 26px">{{ $t('common.welcome_to_bookball') }}</h1>
        <p class="muted" style="margin: 8px 0 20px">{{ $t('auth.welcome.sub') }}</p>
        <form class="grid" style="gap: 14px" @submit.prevent="form.post(route('welcome.store'))">
            <div class="field">
                <label for="name">{{ $t('common.your_name') }}</label>
                <input id="name" v-model="form.name" class="input" autocomplete="name" autofocus />
                <div v-if="form.errors.name" class="err">{{ form.errors.name }}</div>
            </div>
            <div class="field">
                <label for="username">{{ $t('auth.welcome.username') }}</label>
                <input id="username" v-model="form.username" class="input mono" autocapitalize="off" placeholder="aram_10" />
                <div v-if="form.errors.username" class="err">{{ form.errors.username }}</div>
            </div>
            <button class="btn block" :disabled="form.processing">{{ $t('common.continue') }}</button>
        </form>
    </AuthLayout>
</template>

<style scoped>
.err { color: var(--hot); font-size: 13px; }
</style>
