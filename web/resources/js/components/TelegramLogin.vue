<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { xsrf } from '@/lib/echo';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, ref } from 'vue';

// "Log in with Telegram" (free): opens our bot, the user shares their phone there, this page waits and logs in.
// context 'login'  → goes to the next page (welcome / home / owner)
// context 'booking' → stays on the page and emits "done" (the booking request is sent right after)
const props = withDefaults(defineProps<{ context?: 'login' | 'booking'; to?: string | null }>(), { context: 'login', to: null });
const emit = defineEmits<{ done: [] }>();

type State = 'idle' | 'waiting' | 'done' | 'expired';
const state = ref<State>('idle');
const url = ref('');
const token = ref('');
const simulate = ref(false);
const simPhone = ref('');
const error = ref('');
let timer: number | undefined;

async function call(method: 'GET' | 'POST', path: string, body?: object) {
    const r = await fetch(path, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.error || data.message || 'error');
    return data;
}

async function start() {
    error.value = '';
    // open the window right away (inside the click), so the browser doesn't block it as a popup
    const win = window.open('', '_blank');
    try {
        const d = await call('POST', route('login.telegram'));
        token.value = d.token;
        url.value = d.url;
        simulate.value = d.simulate;
        state.value = 'waiting';
        if (win && !d.simulate) win.location.href = d.url;
        else win?.close();
        poll();
    } catch (e) {
        win?.close();
        error.value = (e as Error).message;
    }
}

function poll() {
    clearTimeout(timer);
    timer = window.setTimeout(async () => {
        try {
            const d = await call('GET', route('login.telegram.status', token.value));
            if (d.status === 'approved') return finish();
            if (d.status === 'expired' || d.status === 'used') {
                state.value = 'expired';
                return;
            }
        } catch {
            /* network hiccup: keep waiting */
        }
        if (state.value === 'waiting') poll();
    }, 2000);
}

async function finish() {
    state.value = 'done';
    try {
        const d = await call('POST', route('login.telegram.complete', token.value), { context: props.context, to: props.to });
        if (props.context === 'booking') {
            emit('done');
        } else if (d.redirect) {
            // the admin panel is not an Inertia page: full load; everything else: Inertia visit
            if (new URL(d.redirect).pathname.startsWith('/admin')) window.location.href = d.redirect;
            else router.visit(d.redirect);
        }
    } catch (e) {
        state.value = 'expired';
        error.value = (e as Error).message;
    }
}

async function simulateConfirm() {
    error.value = '';
    try {
        await call('POST', route('login.telegram.simulate', token.value), { phone: simPhone.value });
        clearTimeout(timer);
        finish();
    } catch (e) {
        error.value = (e as Error).message;
    }
}

function cancel() {
    clearTimeout(timer);
    state.value = 'idle';
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="tg-login">
        <button v-if="state === 'idle'" type="button" class="btn block tg-btn" @click="start">
            <Icon name="send" />{{ trans('auth.telegram.button') }}
        </button>

        <div v-else-if="state === 'waiting'" class="tg-wait card">
            <div class="row" style="gap: 10px"><span class="spinner" aria-hidden="true"></span><b>{{ trans('auth.telegram.waiting') }}</b></div>
            <div class="row" style="gap: 8px; margin-top: 12px; flex-wrap: wrap">
                <a v-if="!simulate" class="btn ghost sm" :href="url" target="_blank" rel="noopener"><Icon name="send" size="sm" />{{ trans('auth.telegram.open') }}</a>
                <button type="button" class="btn ghost sm" @click="cancel">{{ trans('auth.telegram.cancel') }}</button>
            </div>
            <!-- development without a bot token: pretend Telegram shared this phone -->
            <form v-if="simulate" class="sim" @submit.prevent="simulateConfirm">
                <b>{{ trans('auth.telegram.simTitle') }}</b>
                <small class="muted">{{ trans('auth.telegram.simSub') }}</small>
                <div class="row" style="gap: 8px">
                    <span class="input" style="width: auto; color: var(--muted)">+374</span>
                    <input v-model="simPhone" class="input mono" inputmode="tel" placeholder="93 000 100" aria-label="phone" />
                </div>
                <button class="btn sm">{{ trans('auth.telegram.simGo') }}</button>
            </form>
        </div>

        <div v-else-if="state === 'done'" class="tg-wait card"><Icon name="check" /> {{ trans('auth.telegram.done') }}</div>

        <div v-else class="tg-wait card">
            <span class="muted">{{ trans('auth.telegram.expired') }}</span>
            <button type="button" class="btn sm" style="margin-top: 10px" @click="start">{{ trans('auth.telegram.retry') }}</button>
        </div>

        <div v-if="error" class="err">{{ error }}</div>
    </div>
</template>

<style scoped>
.tg-btn { background: #2aabee; color: #fff; }
.tg-btn:hover { box-shadow: 0 10px 26px -10px rgba(42, 171, 238, 0.6); }
.tg-wait { padding: 14px; display: flex; flex-direction: column; font-size: 14px; }
.sim { margin-top: 14px; padding-top: 12px; border-top: 1px dashed var(--line-2); display: grid; gap: 8px; }
.sim small { font-size: 12px; }
.err { color: var(--hot); font-size: 13px; margin-top: 8px; }
.spinner { width: 16px; height: 16px; border-radius: 50%; border: 2px solid var(--line-2); border-top-color: #2aabee; animation: spin 0.8s linear infinite; flex: none; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
</style>
