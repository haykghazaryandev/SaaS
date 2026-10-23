<script setup lang="ts">
import CodeInput from '@/components/CodeInput.vue';
import Icon from '@/components/Icon.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import type { SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

// Login = registration: phone → 4-digit code from Telegram → (first time) name
const props = defineProps<{ to: string | null; phone: string | null }>();
const page = usePage<SharedData>();
const phoneForm = useForm({ phone: '' });
const codeForm = useForm({ code: '', to: props.to });
const codeBox = ref<InstanceType<typeof CodeInput>>();

function send() {
    phoneForm.post(route('login.code'), { preserveScroll: true });
}
function verify(code: string) {
    codeForm.code = code;
    codeForm.post(route('login.verify'), { onError: () => codeBox.value?.clear() });
}
function change() {
    router.post(route('login.change'));
}
function resend() {
    if (props.phone) router.post(route('login.code'), { phone: props.phone }, { preserveScroll: true });
}
</script>

<template>
    <AuthLayout>
        <Head :title="$t('auth.title')" />
        <h1 style="font-size: 26px">{{ $t('auth.title') }}</h1>

        <!-- step 1: phone -->
        <form v-if="!phone" @submit.prevent="send">
            <p class="muted" style="margin: 8px 0 20px">{{ $t('auth.sub') }}</p>
            <div class="field">
                <label for="phone">{{ $t('auth.phone') }}</label>
                <div class="row" style="gap: 8px">
                    <span class="input" style="width: auto; color: var(--muted)">+374</span>
                    <input id="phone" v-model="phoneForm.phone" class="input mono" inputmode="tel" autocomplete="tel" placeholder="91 234 567" autofocus />
                </div>
                <div v-if="phoneForm.errors.phone" class="err">{{ phoneForm.errors.phone }}</div>
            </div>
            <div class="tg-note"><Icon name="send" size="sm" />{{ $t('auth.tgNote') }}</div>
            <button class="btn block" :disabled="phoneForm.processing">{{ $t('auth.send') }}</button>
        </form>

        <!-- step 2: code -->
        <div v-else>
            <p class="muted" style="margin: 8px 0 20px">{{ $t('auth.enter', { phone }) }}</p>
            <CodeInput ref="codeBox" @complete="verify" />
            <p v-if="page.props.devCode" style="text-align: center; margin: 14px 0 0">
                <span class="pill lime">{{ $t('auth.devCode', { code: page.props.devCode }) }}</span>
            </p>
            <div v-if="codeForm.errors.code" class="err" style="text-align: center">{{ codeForm.errors.code }}</div>
            <div class="row" style="justify-content: space-between; margin-top: 20px">
                <button type="button" class="btn ghost sm" @click="change"><Icon name="back" size="sm" />{{ $t('auth.change') }}</button>
                <button type="button" class="btn ghost sm" @click="resend">{{ $t('auth.resend') }}</button>
            </div>
        </div>
    </AuthLayout>
</template>

<style scoped>
.err { color: var(--hot); font-size: 13px; margin-top: 8px; }
.tg-note { display: flex; gap: 10px; align-items: center; margin: 18px 0; padding: 10px 12px; border-radius: 12px; background: var(--blue-soft); color: var(--blue); font-size: 13px; font-weight: 700; }
</style>
