<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useShared } from '@/composables/useShared';
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

/**
 * "Connect Telegram" button. Opens t.me/<bot>?start=<token> and polls /telegram/status
 * until the user presses Start in the bot. Emits "linked" and reloads the shared `auth` prop.
 *
 *   <TelegramConnect />                 card with title and text
 *   <TelegramConnect compact />         only the button
 */
const props = defineProps<{ compact?: boolean }>();
const emit = defineEmits<{ linked: [] }>();

const { user } = useShared();
const state = ref<'idle' | 'waiting' | 'done' | 'error'>(user.value?.telegram ? 'done' : 'idle');
const botUrl = ref<string | null>(null);
let timer: number | undefined;
let stopAt = 0;

function xsrf(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function connect() {
    // open the window right away (inside the click) so popup blockers allow it, then point it to the bot
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
    stopAt = Date.now() + 30 * 60 * 1000; // the link is valid for 30 minutes
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
                state.value = 'done';
                emit('linked');
                router.reload({ only: ['auth'] });
            }
        } catch {
            /* network hiccup: try again on the next tick */
        }
    }, 2500);
}

onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div :class="props.compact ? 'tg-connect' : 'card tg-connect'" style="padding: 16px">
        <template v-if="!props.compact">
            <div style="font-weight: 600">{{ $t('telegram.connect.title') }}</div>
            <p class="muted" style="margin: 4px 0 12px; font-size: 14px">{{ $t('telegram.connect.sub') }}</p>
        </template>

        <div v-if="state === 'done'" class="pill lime"><Icon name="check" size="sm" /> {{ $t('telegram.connect.done') }}</div>

        <template v-else-if="state === 'waiting'">
            <p style="margin: 0 0 8px; font-size: 14px" role="status" aria-live="polite">
                <span class="tg-dot" aria-hidden="true"></span> {{ $t('telegram.connect.waiting') }}
            </p>
            <a v-if="botUrl" :href="botUrl" target="_blank" rel="noopener" class="btn ghost sm">{{ $t('telegram.connect.open_again') }}</a>
        </template>

        <template v-else>
            <button type="button" class="btn" @click="connect"><Icon name="send" /> {{ $t('telegram.connect.button') }}</button>
            <p v-if="state === 'error'" style="margin: 8px 0 0; font-size: 13px; color: var(--hot)">{{ $t('telegram.connect.error') }}</p>
        </template>
    </div>
</template>

<style scoped>
.tg-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--accent);
    margin-right: 6px;
    animation: tg-pulse 1.2s ease-in-out infinite;
}
@keyframes tg-pulse {
    50% {
        opacity: 0.25;
    }
}
</style>
