<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

// first login: the player tells us their name, and their phone when the account has none
// (username + password, Google, email): nothing else opens until it is given (RequirePhone)
const props = defineProps<{ name: string | null; username?: string | null; needsPhone?: boolean }>();
const form = useForm({ name: props.name ?? '', username: props.username ?? '', phone: '' });
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
            <!-- accounts without a phone give it here (not confirmed): the place may call about a booking -->
            <div v-if="needsPhone" class="field">
                <label for="phone">{{ $t('auth.welcome.phone') }}</label>
                <div class="row" style="gap: 8px">
                    <span class="input" style="width: auto; color: var(--muted)">+374</span>
                    <input id="phone" v-model="form.phone" class="input mono" inputmode="tel" autocomplete="tel" placeholder="91 234 567" />
                </div>
                <div v-if="form.errors.phone" class="err">{{ form.errors.phone }}</div>
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
