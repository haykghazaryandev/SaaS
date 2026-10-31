<script setup lang="ts">
// Phones: "put the app on your home screen", once per device (closing it hides it for good on this phone).
import Icon from '@/components/Icon.vue';
import { bannerDismissed, dismissBanner, installable, promptInstall } from '@/lib/pwa';
import { onMounted, ref } from 'vue';

const closed = ref(true);
onMounted(() => (closed.value = bannerDismissed()));
function close() {
    closed.value = true;
    dismissBanner();
}
</script>

<template>
    <div v-if="!closed && installable()" class="ibanner" data-test="install-banner">
        <span class="ii"><Icon name="install" /></span>
        <span class="it">
            <b>{{ $t('pwa.install.banner.title') }}</b>
        </span>
        <button type="button" class="btn sm" @click="promptInstall">{{ $t('pwa.install.banner.cta') }}</button>
        <button type="button" class="ix" :aria-label="$t('pwa.install.banner.later')" :title="$t('pwa.install.banner.later')" @click="close">
            <Icon name="x" size="sm" />
        </button>
    </div>
</template>

<style scoped>
.ibanner {
    display: none;
}
@media (max-width: 900px) {
    .ibanner {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 14px;
        position: relative;
        padding: 10px 8px 10px 12px;
        border-radius: 16px;
        background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 9%, var(--surface)), var(--surface) 60%);
        border: 1px solid color-mix(in srgb, var(--accent) 45%, transparent);
    }
    /* player Home: <main> has no top padding (owner Now's has), so the banner would touch the header */
    .app-main > .ibanner {
        margin-top: 18px;
    }
}
.ii {
    flex: none;
    width: 38px;
    height: 38px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    background: var(--surface-2);
    color: var(--accent);
}
.it {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
    flex: 1;
}
.it b {
    font-size: 14px;
}
.ibanner .btn {
    flex: none;
}
.ix {
    flex: none;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: none;
    color: var(--muted);
    display: grid;
    place-items: center;
}
.ix:hover {
    color: var(--ink);
    background: var(--surface-2);
}
/* small phones: no icon, so the title, the button and ✕ stay on one row */
@media (max-width: 420px) {
    .ibanner {
        gap: 8px;
        padding: 10px 8px 10px 14px;
    }
    .ii {
        display: none;
    }
}

/* motion: slide in, the glow pulses 3 times and stops, a slow shimmer on Install. Reduced motion: none of it */
@media (prefers-reduced-motion: no-preference) {
    .ibanner {
        animation: ib-in 0.36s cubic-bezier(0.2, 0.8, 0.2, 1) both;
    }
    /* the glow is a pseudo-element's opacity, so only opacity animates */
    .ibanner::before {
        content: '';
        position: absolute;
        inset: -1px;
        border-radius: inherit;
        pointer-events: none;
        box-shadow:
            0 0 0 1px color-mix(in srgb, var(--accent) 60%, transparent),
            0 6px 26px -4px color-mix(in srgb, var(--accent) 45%, transparent);
        opacity: 0;
        animation: bb-install-glow 1.5s ease-in-out 0.45s 3;
    }
    .ibanner .btn {
        position: relative;
        overflow: hidden;
    }
    .ibanner .btn::after {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        background: linear-gradient(105deg, transparent 35%, rgba(255, 255, 255, 0.5) 50%, transparent 65%);
        transform: translateX(-100%);
        animation: bb-install-shine 5s ease-in-out 1.2s infinite;
    }
}
@keyframes ib-in {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
}
/* bb-install-glow / bb-install-shine: shared with the menu items, in bookball.css */
</style>
