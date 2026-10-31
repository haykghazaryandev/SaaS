<script setup lang="ts">
import Logo from '@/components/Logo.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// A page that isn't there, isn't allowed, or broke: our look, what happened in plain words, and a way back
// (instead of the framework's white page).
const props = defineProps<{ status: number }>();
const kind = computed(() => ({ 403: 'forbidden', 404: 'not_found', 503: 'down' })[props.status as 403 | 404 | 503] ?? 'broken');
const back = () => (history.length > 1 ? history.back() : (location.href = '/'));
</script>

<template>
    <Head :title="$t('errors.' + kind + '_title')" />
    <main class="errp" data-test="error-page">
        <Link href="/"><Logo /></Link>
        <div class="code">{{ status }}</div>
        <h1>{{ $t('errors.' + kind + '_title') }}</h1>
        <p class="muted">{{ $t('errors.' + kind + '_text') }}</p>
        <div class="acts">
            <button type="button" class="btn ghost" @click="back">{{ $t('errors.back') }}</button>
            <Link href="/" class="btn">{{ $t('errors.home') }}</Link>
        </div>
    </main>
</template>

<style scoped>
.errp { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 10px; padding: 24px 16px; background: var(--bg); color: var(--ink); }
.code { font: 700 72px/1 var(--display); color: var(--accent); margin-top: 18px; }
h1 { font: 600 24px var(--display); margin: 4px 0 0; }
p { max-width: 380px; margin: 0; }
.acts { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; margin-top: 12px; }
</style>
