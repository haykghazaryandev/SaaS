<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

// the link from the "Forgot password?" email: a new password, then the person is logged in
const props = defineProps<{ token: string; email: string }>();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });

function save() {
    form.post(route('login.reset.store'), { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <AuthLayout>
        <Head :title="$t('auth.reset.new_title')" />
        <h1 style="font-size: 26px">{{ $t('auth.reset.new_title') }}</h1>
        <p class="muted" style="margin: 8px 0 20px">{{ email }}</p>
        <form class="grid" style="gap: 14px" @submit.prevent="save">
            <div class="field">
                <label for="password">{{ $t('auth.password.password') }}</label>
                <input id="password" v-model="form.password" class="input" type="password" autocomplete="new-password" autofocus />
                <div class="muted hint">{{ $t('auth.password.password_hint') }}</div>
                <div v-if="form.errors.password" class="err">{{ form.errors.password }}</div>
                <div v-if="form.errors.email" class="err">{{ form.errors.email }}</div>
            </div>
            <div class="field">
                <label for="password_confirmation">{{ $t('auth.password.confirm') }}</label>
                <input id="password_confirmation" v-model="form.password_confirmation" class="input" type="password" autocomplete="new-password" />
            </div>
            <button class="btn block" :disabled="form.processing">{{ $t('auth.reset.save') }}</button>
            <Link :href="route('login')" class="muted" style="text-align: center; font-size: 13px; font-weight: 700">{{
                $t('auth.reset.ask_again')
            }}</Link>
        </form>
    </AuthLayout>
</template>

<style scoped>
.err {
    color: var(--hot);
    font-size: 13px;
    margin-top: 8px;
}
.hint {
    font-size: 12px;
    margin-top: 6px;
}
</style>
