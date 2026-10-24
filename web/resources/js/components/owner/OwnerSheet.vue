<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { onBeforeUnmount, onMounted } from 'vue';

// Modal sheet (bottom sheet on phones). Closes on Escape and on a click outside.
defineProps<{ title?: string; sub?: string; wide?: boolean }>();
const emit = defineEmits<{ close: [] }>();
const onKey = (e: KeyboardEvent) => e.key === 'Escape' && emit('close');
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <div class="scrim" @click.self="emit('close')">
        <div :class="['sheet', { wide }]" role="dialog" aria-modal="true" :aria-label="title">
            <template v-if="title">
                <div class="row" :style="{ marginBottom: sub ? '4px' : '14px', alignItems: 'flex-start' }">
                    <h3>{{ title }}</h3>
                    <button type="button" class="x" :aria-label="$t('common.close')" @click="emit('close')"><Icon name="x" /></button>
                </div>
                <p v-if="sub" class="muted" style="margin: 0 0 16px; font-size: 14px">{{ sub }}</p>
            </template>
            <slot />
        </div>
    </div>
</template>
