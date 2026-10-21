<script setup lang="ts">
import CodeInput from '@/components/CodeInput.vue';
import Icon from '@/components/Icon.vue';
import TelegramLogin from '@/components/TelegramLogin.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import type { SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

// Login = registration.
// 1. "Log in with Telegram" (main, free: Telegram confirms the phone)
// 2. email code (backup for people without Telegram)
// 3. phone + code (development, or when the paid Telegram Gateway is switched on)
const props = defineProps<{
    to: string | null;
    phone: string | null;
    email: string | null;
    telegram: { bot: string; simulate: boolean };
    phoneCode: boolean;
}>();
const page = usePage<SharedData>();

const mode = ref<'main' | 'email' | 'phone'>(props.email ? 'email' : props.phone ? 'phone' : 'main');

const emailForm = useForm({ email: '' });
const phoneForm = useForm({ phone: '' });
const codeForm = useForm({ code: '', to: props.to });
const codeBox = ref<InstanceType<typeof CodeInput>>();

function sendEmail() {
    emailForm.post(route('login.email'), { preserveScroll: true });
}
function sendPhone() {
    phoneForm.post(route('login.code'), { preserveScroll: true });
}
function verify(code: string) {
    codeForm.code = code;
    codeForm.post(route(props.email ? 'login.email.verify' : 'login.verify'), { onError: () => codeBox.value?.clear() });
}
function back() {
    router.post(route('login.change'), {}, { onSuccess: () => (mode.value = 'main') });
}
function resend() {
    if (props.email) router.post(route('login.email'), { email: props.email }, { preserveScroll: true });
    else if (props.phone) router.post(route('login.code'), { phone: props.phone }, { preserveScroll: true });
}
</script>

<template>
    <AuthLayout>
        <Head :title="$t('auth.title')" />
        <h1 style="font-size: 26px">{{ $t('auth.title') }}</h1>

        <!-- a code was sent (email or phone): enter it -->
        <div v-if="email || phone">
            <p class="muted" style="margin: 8px 0 20px">{{ email ? $t('auth.email.enter', { email }) : $t('auth.enter', { phone: phone ?? '' }) }}</p>
            <CodeInput ref="codeBox" @complete="verify" />
            <p v-if="page.props.devCode" style="text-align: center; margin: 14px 0 0">
                <span class="pill lime">{{ $t('auth.devCode', { code: page.props.devCode }) }}</span>
            </p>
            <div v-if="codeForm.errors.code" class="err" style="text-align: center">{{ codeForm.errors.code }}</div>
            <div class="row" style="justify-content: space-between; margin-top: 20px">
                <button type="button" class="btn ghost sm" @click="back"><Icon name="back" size="sm" />{{ $t('back') }}</button>
                <button type="button" class="btn ghost sm" @click="resend">{{ $t('auth.resend') }}</button>
            </div>
        </div>

        <!-- 1. Telegram (main) -->
        <div v-else-if="mode === 'main'">
            <p class="muted" style="margin: 8px 0 20px">{{ $t('auth.sub2') }}</p>
            <TelegramLogin :to="to" />
            <div class="or"><span>{{ $t('auth.or') }}</span></div>
            <button type="button" class="btn ghost block" @click="mode = 'email'"><Icon name="msg" size="sm" />{{ $t('auth.withEmail') }}</button>
            <button v-if="phoneCode" type="button" class="linkish" @click="mode = 'phone'">{{ $t('auth.withPhoneDev') }}</button>
        </div>

        <!-- 2. email -->
        <form v-else-if="mode === 'email'" @submit.prevent="sendEmail">
            <p class="muted" style="margin: 8px 0 20px">{{ $t('auth.email.note') }}</p>
            <div class="field">
                <label for="email">{{ $t('auth.email.label') }}</label>
                <input id="email" v-model="emailForm.email" class="input" type="email" autocomplete="email" placeholder="name@mail.com" autofocus />
                <div v-if="emailForm.errors.email" class="err">{{ emailForm.errors.email }}</div>
            </div>
            <button class="btn block" style="margin-top: 16px" :disabled="emailForm.processing">{{ $t('auth.email.send') }}</button>
            <button type="button" class="linkish" @click="mode = 'main'"><Icon name="back" size="sm" />{{ $t('auth.telegram.button') }}</button>
        </form>

        <!-- 3. phone + code (development / Gateway) -->
        <form v-else @submit.prevent="sendPhone">
            <p class="muted" style="margin: 8px 0 20px">{{ $t('auth.sub') }}</p>
            <div class="field">
                <label for="phone">{{ $t('auth.phone') }}</label>
                <div class="row" style="gap: 8px">
                    <span class="input" style="width: auto; color: var(--muted)">+374</span>
                    <input id="phone" v-model="phoneForm.phone" class="input mono" inputmode="tel" autocomplete="tel" placeholder="91 234 567" autofocus />
                </div>
                <div v-if="phoneForm.errors.phone" class="err">{{ phoneForm.errors.phone }}</div>
            </div>
            <button class="btn block" style="margin-top: 16px" :disabled="phoneForm.processing">{{ $t('auth.send') }}</button>
            <button type="button" class="linkish" @click="mode = 'main'"><Icon name="back" size="sm" />{{ $t('auth.telegram.button') }}</button>
        </form>
    </AuthLayout>
</template>

<style scoped>
.err { color: var(--hot); font-size: 13px; margin-top: 8px; }
.or { display: flex; align-items: center; gap: 12px; margin: 18px 0; color: var(--faint); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; }
.or::before, .or::after { content: ''; flex: 1; height: 1px; background: var(--line-2); }
.linkish { display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; margin-top: 14px; background: none; border: 0; color: var(--muted); font-size: 13px; font-weight: 700; }
.linkish:hover { color: var(--ink); }
</style>
