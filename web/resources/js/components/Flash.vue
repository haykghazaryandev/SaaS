<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useShared } from '@/composables/useShared';
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

// short toast for flash messages from the server (success / error), and for form errors nobody would see:
// an error whose field is not on the screen (e.g. "you already have 2 requests waiting" while the form is off-screen)
const { flash } = useShared();
const msg = ref<{ text: string; error: boolean } | null>(null);
let timer: number | undefined;
function show(text: string, error: boolean) {
    msg.value = { text, error };
    clearTimeout(timer);
    timer = window.setTimeout(() => (msg.value = null), error ? 5000 : 3200);
}
/** a visible input for this error key (#key or [name=key]): the page shows the error next to it */
function shownInForm(key: string): boolean {
    const el = document.getElementById(key) ?? document.querySelector(`[name="${CSS.escape(key)}"]`);
    if (!el) return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.bottom > 0 && r.top < window.innerHeight;
}
let off: (() => void) | undefined;
onMounted(
    () =>
        (off = router.on('error', (e) => {
            const errors = e.detail.errors as Record<string, string>;
            const key = Object.keys(errors).find((k) => !shownInForm(k));
            if (key) show(errors[key], true);
        })),
);
onBeforeUnmount(() => off?.());   // the layout (and this toast) is mounted again on every page
watch(
    flash,
    (f) => {
        const text = f?.success || f?.error;
        if (text) show(text, !!f?.error);
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div v-if="msg" :class="['toast', { error: msg.error }]" :role="msg.error ? 'alert' : 'status'">
        <Icon :name="msg.error ? 'x' : 'check'" />{{ msg.text }}
    </div>
</template>
