<script setup lang="ts">
import CodeInput from '@/components/CodeInput.vue';
import GoogleIcon from '@/components/GoogleIcon.vue';
import Icon from '@/components/Icon.vue';
import TelegramLogin from '@/components/TelegramLogin.vue';
import { inTelegram } from '@/lib/telegram';
import AuthLayout from '@/layouts/AuthLayout.vue';
import type { SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Login = registration. The main screen, top to bottom:
// 1. email + password: log in, or sign up (email, password); "Forgot password?" emails a link
// 2. "Log in with Telegram" (free: Telegram confirms the phone)
// 3. "Continue with Google" (when Google is configured), email code (people without Telegram)
// 4. phone + code (development, or when the paid Telegram Gateway is switched on)
// Accounts without a phone add it on the welcome page next.
const props = defineProps<{
    to: string | null;
    phone: string | null;
    email: string | null;
    telegram: { bot: string; simulate: boolean };
    phoneCode: boolean;
    testAccounts?: boolean;
    emailLogin?: boolean;
    google?: boolean;
}>();
const page = usePage<SharedData>();
// Only the Telegram login (no title, password form, Google…) with a Back button:
// - inside Telegram (Mini App); Google refuses in-app browsers anyway
// - after "Log in with Telegram" was pressed (until it is cancelled with Back)
const tg = inTelegram();
const tgActive = ref(false);
const telegramBox = ref<{ cancel: () => void }>();
const onlyTelegram = computed(() => tg || tgActive.value);
function goBack() {
    if (tgActive.value) telegramBox.value?.cancel();   // back to all the ways to log in
    else if (history.length > 1) history.back();        // Mini App: the page the person came from
    else router.visit(route('home'));
}

const mode = ref<'main' | 'email' | 'phone' | 'forgot'>(props.email ? 'email' : props.phone ? 'phone' : 'main');

const emailForm = useForm({ email: '' });
const phoneForm = useForm({ phone: '' });
const codeForm = useForm({ code: '', to: props.to });
const codeBox = ref<InstanceType<typeof CodeInput>>();
const signUp = ref(false);
const passwordForm = useForm({ email: '', password: '', password_confirmation: '', to: props.to });
const forgotForm = useForm({ email: '' });
const forgotSent = ref(false);

function toggleSignUp() {
    signUp.value = !signUp.value;
    passwordForm.clearErrors();
}
function openForgot() {
    // the typed email is likely the one to restore
    forgotForm.email = passwordForm.email;
    forgotSent.value = false;
    mode.value = 'forgot';
}
function sendForgot() {
    forgotForm.post(route('login.forgot'), { preserveScroll: true, onSuccess: () => (forgotSent.value = true) });
}

function sendPassword() {
    passwordForm.post(route(signUp.value ? 'login.register' : 'login.password'), {
        preserveScroll: true,
        onFinish: () => passwordForm.reset('password', 'password_confirmation'),
    });
}

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
        <h1 v-if="!onlyTelegram" style="font-size: 26px">{{ $t('auth.title') }}</h1>

        <!-- a code was sent (email or phone): enter it -->
        <div v-if="email || phone">
            <p class="muted" style="margin: 8px 0 20px">{{ email ? $t('auth.email.enter', { email }) : $t('auth.enter', { phone: phone ?? '' }) }}</p>
            <CodeInput ref="codeBox" @complete="verify" />
            <p v-if="page.props.devCode" style="text-align: center; margin: 14px 0 0">
                <span class="pill lime">{{ $t('auth.dev_code', { code: page.props.devCode }) }}</span>
            </p>
            <div v-if="codeForm.errors.code" class="err" style="text-align: center">{{ codeForm.errors.code }}</div>
            <div class="row" style="justify-content: space-between; margin-top: 20px">
                <button type="button" class="btn ghost sm" @click="back"><Icon name="back" size="sm" />{{ $t('common.back') }}</button>
                <button type="button" class="btn ghost sm" @click="resend">{{ $t('auth.resend') }}</button>
            </div>
        </div>

        <!-- main: email + password first, then Telegram, Google and the email code -->
        <div v-else-if="mode === 'main'">
            <form v-if="!onlyTelegram" class="grid" style="gap: 14px; margin-top: 20px" @submit.prevent="sendPassword">
                <div class="field">
                    <label for="login_email">{{ $t('auth.email.label') }}</label>
                    <input id="login_email" v-model="passwordForm.email" class="input" type="email" autocomplete="email" placeholder="name@mail.com" />
                    <div v-if="passwordForm.errors.email" class="err">{{ passwordForm.errors.email }}</div>
                </div>
                <div class="field">
                    <div class="row" style="justify-content: space-between; align-items: baseline">
                        <label for="password">{{ $t('auth.password.password') }}</label>
                        <button v-if="!signUp" type="button" class="textlink" @click="openForgot">{{ $t('auth.password.forgot') }}</button>
                    </div>
                    <input id="password" v-model="passwordForm.password" class="input" type="password" :autocomplete="signUp ? 'new-password' : 'current-password'" />
                    <div v-if="signUp" class="muted hint">{{ $t('auth.password.password_hint') }}</div>
                    <div v-if="passwordForm.errors.password" class="err">{{ passwordForm.errors.password }}</div>
                </div>
                <div v-if="signUp" class="field">
                    <label for="password_confirmation">{{ $t('auth.password.confirm') }}</label>
                    <input id="password_confirmation" v-model="passwordForm.password_confirmation" class="input" type="password" autocomplete="new-password" />
                </div>
                <button class="btn block" :disabled="passwordForm.processing">{{ $t(signUp ? 'auth.password.register' : 'auth.password.login') }}</button>
                <button type="button" class="linkish" style="margin-top: 0" @click="toggleSignUp">
                    {{ $t(signUp ? 'auth.password.to_login' : 'auth.password.to_register') }}
                </button>
            </form>

            <div v-if="!onlyTelegram" class="or"><span>{{ $t('auth.or') }}</span></div>
            <TelegramLogin ref="telegramBox" :to="to" :cancel-button="false" @active="tgActive = $event" />
            <div v-if="!tgActive" class="grid" style="gap: 10px; margin-top: 14px">
                <!-- a full page visit: Google's page, then back to /login/google/callback -->
                <a v-if="google && !tg" class="btn ghost block" :href="route('login.google', to ? { to } : {})">
                    <GoogleIcon />
                    {{ $t('auth.google.button') }}
                </a>
                <button v-if="emailLogin" type="button" class="btn ghost block" @click="mode = 'email'"><Icon name="msg" size="sm" />{{ $t('auth.with_email') }}</button>
            </div>
            <button v-if="onlyTelegram" type="button" class="linkish" @click="goBack"><Icon name="back" size="sm" />{{ $t('common.back') }}</button>
            <button v-if="phoneCode && !tgActive" type="button" class="linkish" @click="mode = 'phone'">{{ $t(testAccounts ? 'auth.with_test_account' : 'auth.with_phone_dev') }}</button>
        </div>

        <!-- forgot password: a link to the account's email -->
        <form v-else-if="mode === 'forgot'" @submit.prevent="sendForgot">
            <h2 style="font-size: 18px; margin: 14px 0 0">{{ $t('auth.reset.title') }}</h2>
            <p class="muted" style="margin: 8px 0 20px">{{ $t(forgotSent ? 'auth.reset.sent' : 'auth.reset.note') }}</p>
            <template v-if="!forgotSent">
                <div class="field">
                    <label for="forgot_email">{{ $t('auth.email.label') }}</label>
                    <input id="forgot_email" v-model="forgotForm.email" class="input" type="email" autocomplete="email" placeholder="name@mail.com" autofocus />
                    <div v-if="forgotForm.errors.email" class="err">{{ forgotForm.errors.email }}</div>
                </div>
                <button class="btn block" style="margin-top: 16px" :disabled="forgotForm.processing">{{ $t('auth.reset.send') }}</button>
            </template>
            <button type="button" class="linkish" @click="mode = 'main'"><Icon name="back" size="sm" />{{ $t('common.back') }}</button>
        </form>

        <!-- email code -->
        <form v-else-if="mode === 'email'" @submit.prevent="sendEmail">
            <p class="muted" style="margin: 8px 0 20px">{{ $t('auth.email.note') }}</p>
            <div class="field">
                <label for="email">{{ $t('auth.email.label') }}</label>
                <input id="email" v-model="emailForm.email" class="input" type="email" autocomplete="email" placeholder="name@mail.com" autofocus />
                <div v-if="emailForm.errors.email" class="err">{{ emailForm.errors.email }}</div>
            </div>
            <button class="btn block" style="margin-top: 16px" :disabled="emailForm.processing">{{ $t('common.send_code') }}</button>
            <button type="button" class="linkish" @click="mode = 'main'"><Icon name="back" size="sm" />{{ $t('auth.telegram.button') }}</button>
        </form>

        <!-- phone + code (development / Gateway) -->
        <form v-else @submit.prevent="sendPhone">
            <p class="muted" style="margin: 8px 0 20px">{{ $t(testAccounts ? 'auth.test_hint' : 'auth.sub') }}</p>
            <div class="field">
                <label for="phone">{{ $t('auth.phone_label') }}</label>
                <div class="row" style="gap: 8px">
                    <span class="input" style="width: auto; color: var(--muted)">+374</span>
                    <input id="phone" v-model="phoneForm.phone" class="input mono" inputmode="tel" autocomplete="tel" placeholder="91 234 567" autofocus />
                </div>
                <div v-if="phoneForm.errors.phone" class="err">{{ phoneForm.errors.phone }}</div>
            </div>
            <button class="btn block" style="margin-top: 16px" :disabled="phoneForm.processing">{{ $t('common.send_code') }}</button>
            <button type="button" class="linkish" @click="mode = 'main'"><Icon name="back" size="sm" />{{ $t('auth.telegram.button') }}</button>
        </form>
    </AuthLayout>
</template>

<style scoped>
.err { color: var(--hot); font-size: 13px; margin-top: 8px; }
.hint { font-size: 12px; margin-top: 6px; }
.textlink { background: none; border: 0; padding: 0; color: var(--muted); font-size: 12px; font-weight: 700; }
.textlink:hover { color: var(--ink); }
.or { display: flex; align-items: center; gap: 12px; margin: 18px 0; color: var(--faint); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; }
.or::before, .or::after { content: ''; flex: 1; height: 1px; background: var(--line-2); }
.linkish { display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; margin-top: 14px; background: none; border: 0; color: var(--muted); font-size: 13px; font-weight: 700; }
.linkish:hover { color: var(--ink); }
</style>
