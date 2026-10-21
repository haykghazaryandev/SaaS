<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

/**
 * A thin, dismissible banner: "Allow the Bookball bot to send confirmations → Open bot".
 * Show it only to users without a Telegram chat (auth.user.telegram === false). The dismissal is remembered in
 * localStorage (and simply not remembered when storage is blocked). Same link flow as TelegramConnect.vue.
 */
const KEY = 'bb.tgBanner.dismissed';
const read = () => {
    try {
        return window.localStorage.getItem(KEY) === '1';
    } catch {
        return false;
    }
};
const hidden = ref(read());
const state = ref<'idle' | 'waiting' | 'error'>('idle');
const botUrl = ref<string | null>(null);
let timer: number | undefined;

function dismiss() {
    hidden.value = true;
    try {
        window.localStorage.setItem(KEY, '1');
    } catch {
        /* storage blocked: hide for this visit only */
    }
}
function xsrf(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}
async function open() {
    // open the window inside the click so popup blockers allow it
    const win = window.open('', '_blank');
    try {
        const res = await fetch(route('telegram.link'), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(String(res.status));
        const data = (await res.json()) as { url: string };
        botUrl.value = data.url;
        if (win) win.location.href = data.url;
        else window.location.href = data.url;
        state.value = 'waiting';
        poll();
    } catch {
        win?.close();
        state.value = 'error';
    }
}
function poll() {
    clearInterval(timer);
    const stopAt = Date.now() + 30 * 60 * 1000;
    timer = window.setInterval(async () => {
        if (Date.now() > stopAt) {
            clearInterval(timer);
            state.value = 'idle';
            return;
        }
        try {
            const res = await fetch(route('telegram.status'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = (await res.json()) as { linked: boolean };
            if (data.linked) {
                clearInterval(timer);
                router.reload({ only: ['auth'] });
            }
        } catch {
            /* try again on the next tick */
        }
    }, 2500);
}
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div v-if="!hidden" class="tgb" role="note">
        <Icon name="send" size="sm" class="tgi" />
        <span class="tgt" :role="state === 'waiting' ? 'status' : undefined">
            <template v-if="state === 'waiting'">{{ $t('player.my.tgWaiting') }}</template>
            <template v-else-if="state === 'error'">{{ $t('player.my.tgError') }}</template>
            <template v-else>{{ $t('player.my.tgAsk') }}</template>
        </span>
        <a v-if="state === 'waiting' && botUrl" :href="botUrl" target="_blank" rel="noopener" class="tga">{{ $t('player.my.tgOpen') }}</a>
        <button v-else type="button" class="tga" @click="open">{{ $t('player.my.tgOpen') }} <Icon name="arrow" size="sm" /></button>
        <button type="button" class="tgx" :aria-label="$t('player.close')" @click="dismiss"><Icon name="x" size="sm" /></button>
    </div>
</template>

<style scoped>
.tgb { display: flex; align-items: center; gap: 10px; padding: 7px 8px 7px 12px; border-radius: 12px; background: var(--blue-soft); color: var(--ink); font-size: 13.5px; }
.tgi { color: var(--blue); }
.tgt { flex: 1; min-width: 0; line-height: 1.35; }
.tga { flex: none; display: inline-flex; align-items: center; gap: 4px; background: none; border: 0; padding: 4px 6px; color: var(--blue); font-weight: 800; font-size: 13.5px; white-space: nowrap; }
.tga:hover { text-decoration: underline; text-underline-offset: 3px; }
.tgx { flex: none; width: 28px; height: 28px; border-radius: 8px; border: 0; background: none; color: var(--muted); display: grid; place-items: center; }
.tgx:hover { background: var(--surface-2); color: var(--ink); }
</style>
