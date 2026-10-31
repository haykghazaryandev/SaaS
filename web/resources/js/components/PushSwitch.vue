<script setup lang="ts">
// "Notifications on this device" (Web Push, lib/push.ts). Hidden when the server has no VAPID keys or inside Telegram.
// The browser asks for permission only when the switch is tapped.
import { disablePush, enablePush, pushState, rememberPushEndpoint, type PushState } from '@/lib/push';
import { promptInstall, pwa } from '@/lib/pwa';
import { inTelegram } from '@/lib/telegram';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{ endpoints: string[] }>();
// tapped: true when the person switched it (not the first check on load)
const emit = defineEmits<{ change: [on: boolean, tapped: boolean] }>();

const page = usePage<SharedData>();
const key = computed(() => page.props.pushKey ?? null);
const state = ref<PushState | null>(null);
const busy = ref(false);
const failed = ref(false);

function set(s: PushState, tapped = true) {
    state.value = s;
    emit('change', s === 'on', tapped);
}

onMounted(async () => {
    if (!key.value || inTelegram()) return;
    set(await pushState(props.endpoints).catch((): PushState => 'unsupported'), false);
});

async function toggle(e: Event) {
    const input = e.target as HTMLInputElement;
    if (!key.value || busy.value) return;
    busy.value = true;
    failed.value = false;
    try {
        if (input.checked) {
            const r = await enablePush(key.value);
            rememberPushEndpoint(r.endpoint ?? null);
            failed.value = r.state === 'off' && Notification.permission === 'granted';
            set(r.state);
        } else {
            rememberPushEndpoint(null);
            set(await disablePush());
        }
    } catch {
        failed.value = true;
        set('off');
    } finally {
        input.checked = state.value === 'on';
        busy.value = false;
    }
}

const help = computed(() => {
    if (failed.value) return 'profile.notifications.device_failed';
    switch (state.value) {
        case 'install':
            return 'profile.notifications.device_install';
        case 'denied':
            return 'profile.notifications.device_denied';
        case 'unsupported':
            return 'profile.notifications.device_unsupported';
        default:
            return 'profile.notifications.device_help';
    }
});
const blocked = computed(() => state.value === 'install' || state.value === 'denied' || state.value === 'unsupported');
</script>

<template>
    <div v-if="key && state" class="psw" data-test="push-switch">
        <label class="psw-row">
            <span>
                <b>{{ $t('profile.notifications.device') }}</b>
                <small :class="failed || state === 'denied' ? 'warn' : 'muted'">{{ $t(help) }}</small>
            </span>
            <input type="checkbox" role="switch" :checked="state === 'on'" :disabled="busy || blocked" @change="toggle" />
        </label>
        <button v-if="state === 'install' && pwa.isIos" type="button" class="btn outline sm" @click="promptInstall">
            {{ $t('profile.notifications.device_install_how') }}
        </button>
    </div>
</template>

<style scoped>
.psw {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 0;
    border-top: 1px solid var(--line);
}
.psw-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    width: 100%;
    cursor: pointer;
}
.psw-row span {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.psw-row small {
    font-size: 12.5px;
}
.psw-row small.warn {
    color: var(--st-pending);
}
.psw-row input {
    appearance: none;
    -webkit-appearance: none;
    flex: none;
    width: 46px;
    height: 28px;
    border-radius: 999px;
    background: var(--surface-3);
    position: relative;
    cursor: pointer;
    transition: background 0.15s;
}
.psw-row input::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--ink);
    transition: transform 0.15s;
}
.psw-row input:checked {
    background: var(--accent);
}
.psw-row input:checked::after {
    transform: translateX(18px);
    background: var(--on-accent);
}
.psw-row input:disabled {
    opacity: 0.4;
    cursor: default;
}
</style>
