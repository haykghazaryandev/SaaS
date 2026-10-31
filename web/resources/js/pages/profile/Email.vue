<script setup lang="ts">
import CodeInput from '@/components/CodeInput.vue';
import Icon from '@/components/Icon.vue';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

// "Your email": add or change it. A 4-digit code goes to the new address; the address is saved (confirmed) only when
// the code is right (EmailVerification). Older accounts with an unconfirmed email confirm it the same way.
const props = defineProps<{ email: string | null; confirmed: boolean; pending: string | null; devCode: string | null }>();

const code = useForm({ code: '' });
const codeBox = ref<InstanceType<typeof CodeInput>>();
const change = useForm({ email: '' });
// the form for a new address (also while a code waits: "Use another email")
const changing = ref(!props.email && !props.pending);
const sending = ref(false);

function verify(value: string) {
    code.code = value;
    code.post(route('me.email.verify'), { preserveScroll: true, onError: () => codeBox.value?.clear() });
}
function sendAgain() {
    sending.value = true;
    router.post(route('me.email.send'), {}, { preserveScroll: true, onFinish: () => (sending.value = false) });
}
// an older account's unconfirmed email: a code to it
function confirmCurrent() {
    change.email = props.email ?? '';
    save();
}
function save() {
    change.post(route('me.email.change'), {
        preserveScroll: true,
        onSuccess: () => {
            change.reset();
            changing.value = false;
        },
    });
}
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('profile.email.title')" />
        <Link :href="route('me.profile')" class="backlink"><Icon name="back" size="sm" />{{ $t('profile.title') }}</Link>
        <h1 class="page-title">{{ $t('profile.email.title') }}</h1>
        <p class="muted lead">{{ $t('profile.email.why') }}</p>

        <section class="card pad box">
            <div v-if="email" class="now">
                <b class="addr">{{ email }}</b>
                <span v-if="confirmed" class="pill green" data-test="email-confirmed"><Icon name="check" size="sm" />{{ $t('profile.email.confirmed') }}</span>
                <span v-else class="pill warn" data-test="email-unconfirmed">{{ $t('profile.email.not_confirmed') }}</span>
            </div>
            <p v-else class="muted">{{ $t('profile.email.none') }}</p>
            <button v-if="email && !confirmed && !pending" type="button" class="btn block" style="margin-top: 14px" :disabled="change.processing" @click="confirmCurrent">
                {{ $t('common.send_code') }}
            </button>
        </section>

        <!-- a code is waiting: the address is saved when it is right -->
        <section v-if="pending && !changing" class="card pad box" data-test="email-pending">
            <p class="muted">{{ $t('profile.email.check', { email: pending }) }}</p>
            <CodeInput ref="codeBox" @complete="verify" />
            <p v-if="devCode" class="dev"><span class="pill lime">{{ $t('auth.dev_code', { code: devCode }) }}</span></p>
            <div v-if="code.errors.code" class="err">{{ code.errors.code }}</div>
            <div class="acts">
                <button type="button" class="btn ghost sm" @click="changing = true">{{ $t('profile.email.other') }}</button>
                <button type="button" class="btn ghost sm" :disabled="sending" data-test="email-resend" @click="sendAgain">{{ $t('auth.resend') }}</button>
            </div>
        </section>

        <section v-else class="card pad box">
            <button v-if="!changing" type="button" class="btn outline block" data-test="email-change-open" @click="changing = true">
                {{ email ? $t('profile.email.change') : $t('profile.email.add') }}
            </button>
            <form v-else @submit.prevent="save">
                <div class="field">
                    <label for="em-new">{{ email ? $t('profile.email.new') : $t('auth.email.label') }}</label>
                    <input id="em-new" v-model="change.email" type="email" class="input" autocomplete="email" autocapitalize="off" spellcheck="false" maxlength="120" required data-test="email-new" />
                    <div v-if="change.errors.email" class="err">{{ change.errors.email }}</div>
                </div>
                <div class="acts end">
                    <button v-if="email || pending" type="button" class="btn ghost" @click="changing = false">{{ $t('common.cancel') }}</button>
                    <button class="btn" :disabled="change.processing || !change.email">{{ $t('common.send_code') }}</button>
                </div>
            </form>
        </section>
    </PlayerLayout>
</template>

<style scoped>
.backlink { display: inline-flex; align-items: center; gap: 8px; color: var(--muted); font-weight: 700; font-size: 14px; margin: 20px 0 14px; }
.backlink:hover { color: var(--accent); }
.box { max-width: 520px; margin-top: 14px; }
.now { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.addr { font-size: 16px; word-break: break-all; }
.now .pill { display: inline-flex; align-items: center; gap: 4px; }
.box > p.muted { margin: 0 0 16px; }
.dev { text-align: center; margin: 14px 0 0; }
.err { color: var(--hot); font-size: 13px; margin-top: 8px; }
.acts { display: flex; justify-content: space-between; gap: 10px; margin-top: 16px; }
.acts.end { justify-content: flex-end; }
</style>
