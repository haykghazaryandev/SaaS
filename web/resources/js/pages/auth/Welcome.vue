<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

// first login: the player tells us their name (registration is finished here)
const props = defineProps<{ name: string | null }>();
const form = useForm({ name: props.name ?? '', username: '', level: 'mid' });
</script>

<template>
    <AuthLayout>
        <Head :title="$t('auth.welcome.title')" />
        <h1 style="font-size: 26px">{{ $t('auth.welcome.title') }}</h1>
        <p class="muted" style="margin: 8px 0 20px">{{ $t('auth.welcome.sub') }}</p>
        <form class="grid" style="gap: 14px" @submit.prevent="form.post(route('welcome.store'))">
            <div class="field">
                <label for="name">{{ $t('auth.welcome.name') }}</label>
                <input id="name" v-model="form.name" class="input" autocomplete="name" autofocus />
                <div v-if="form.errors.name" class="err">{{ form.errors.name }}</div>
            </div>
            <div class="field">
                <label for="username">{{ $t('auth.welcome.username') }}</label>
                <input id="username" v-model="form.username" class="input mono" autocapitalize="off" placeholder="aram_10" />
                <div v-if="form.errors.username" class="err">{{ form.errors.username }}</div>
            </div>
            <div class="field">
                <label>{{ $t('auth.welcome.level') }}</label>
                <div class="chips">
                    <button v-for="l in ['beginner', 'mid', 'pro']" :key="l" type="button" class="chip" :aria-pressed="form.level === l" @click="form.level = l">{{ $t('level.' + l) }}</button>
                </div>
            </div>
            <button class="btn block" :disabled="form.processing">{{ $t('auth.welcome.go') }}</button>
        </form>
    </AuthLayout>
</template>

<style scoped>
.err { color: var(--hot); font-size: 13px; }
</style>
