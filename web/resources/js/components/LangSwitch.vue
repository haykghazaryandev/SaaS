<script setup lang="ts">
import { useShared } from '@/composables/useShared';

const { locale } = useShared();
const LABELS = { en: 'EN', hy: 'ՀԱՅ', ru: 'РУ' } as const;

// the server remembers the language (session + profile); a full reload loads the new texts
function set(l: string) {
    const url = new URL(window.location.href);
    url.searchParams.set('lang', l);
    window.location.href = url.toString();
}
</script>

<template>
    <div class="lang" role="group" aria-label="Language">
        <button v-for="(label, l) in LABELS" :key="l" type="button" :aria-pressed="locale === l" @click="set(l)">{{ label }}</button>
    </div>
</template>
