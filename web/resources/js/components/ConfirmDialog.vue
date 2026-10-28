<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { answer, confirmState } from '@/composables/useConfirm';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// The app's "Are you sure?" (see useConfirm): a small card in the middle, No / Yes. Escape or a tap outside = No.
const yes = ref<HTMLButtonElement | null>(null);
const onKey = (e: KeyboardEvent) => confirmState.open && e.key === 'Escape' && answer(false);
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
watch(
    () => confirmState.open,
    (o) => o && nextTick(() => yes.value?.focus()),
);
</script>

<template>
    <Transition name="cf">
        <div v-if="confirmState.open" class="cf-scrim" @click.self="answer(false)">
            <div class="cf" role="alertdialog" aria-modal="true" :aria-label="confirmState.open.title" data-test="confirm">
                <span :class="['cf-ico', { danger: confirmState.open.danger }]"><Icon :name="confirmState.open.danger ? 'alert' : 'check'" /></span>
                <h3>{{ confirmState.open.title }}</h3>
                <p v-if="confirmState.open.text">{{ confirmState.open.text }}</p>
                <div class="cf-btns">
                    <button type="button" class="btn ghost" data-test="confirm-no" @click="answer(false)">{{ $t('common.cancel') }}</button>
                    <button
                        ref="yes"
                        type="button"
                        :class="['btn', { danger: confirmState.open.danger }]"
                        data-test="confirm-yes"
                        @click="answer(true)"
                    >
                        {{ confirmState.open.ok || $t('common.yes') }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.cf-scrim { position: fixed; inset: 0; z-index: 400; background: rgba(4, 8, 6, 0.62); backdrop-filter: blur(3px); display: grid; place-items: center; padding: 16px; }
.cf { width: 100%; max-width: 360px; background: var(--surface); border: 1px solid var(--line-2); border-radius: 20px; padding: 20px 18px 16px; text-align: center; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45); }
.cf h3 { margin: 10px 0 0; font-size: 17px; line-height: 1.3; }
.cf p { margin: 8px 0 0; color: var(--muted); font-size: 14px; line-height: 1.45; white-space: pre-line; overflow-wrap: anywhere; }
.cf-ico { display: inline-grid; place-items: center; width: 44px; height: 44px; border-radius: 50%; background: var(--accent-soft, rgba(190, 242, 100, 0.14)); color: var(--accent); }
.cf-ico.danger { background: var(--hot-soft); color: var(--hot); }
.cf-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 18px; }
.btn.danger { background: var(--hot); color: #fff; border-color: transparent; }
.cf-enter-active, .cf-leave-active { transition: opacity 0.15s; }
.cf-enter-active .cf, .cf-leave-active .cf { transition: transform 0.15s; }
.cf-enter-from, .cf-leave-to { opacity: 0; }
.cf-enter-from .cf { transform: scale(0.96); }
</style>
