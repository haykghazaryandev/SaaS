<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useShared } from '@/composables/useShared';
import { ref, watch } from 'vue';

// short toast for flash messages from the server (success / error)
const { flash } = useShared();
const msg = ref<{ text: string; error: boolean } | null>(null);
let timer: number | undefined;
watch(
    flash,
    (f) => {
        const text = f?.success || f?.error;
        if (!text) return;
        msg.value = { text, error: !!f?.error };
        clearTimeout(timer);
        timer = window.setTimeout(() => (msg.value = null), 3200);
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div v-if="msg" class="toast" :style="msg.error ? 'background: var(--hot); color: #fff' : ''" role="status">
        <Icon :name="msg.error ? 'x' : 'check'" />{{ msg.text }}
    </div>
</template>
