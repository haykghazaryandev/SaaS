<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { onBeforeUnmount, onMounted } from 'vue';

// A small yes / no dialog (remove a friend, block a player).
defineProps<{ title: string; text?: string; yes: string; busy?: boolean }>();
const emit = defineEmits<{ close: []; confirm: [] }>();
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('close');
}
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div class="scrim" @click.self="emit('close')">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="cs-t">
                <div class="row" style="align-items: flex-start">
                    <h3 id="cs-t" style="margin: 0; overflow-wrap: anywhere">{{ title }}</h3>
                    <button type="button" class="x" :aria-label="$t('player.close')" @click="emit('close')"><Icon name="x" /></button>
                </div>
                <p v-if="text" class="muted" style="margin: 10px 0 18px; font-size: 14px">{{ text }}</p>
                <div class="row">
                    <button type="button" class="btn ghost" style="flex: 1" @click="emit('close')">{{ $t('friends.cancelBtn') }}</button>
                    <button type="button" class="btn red" style="flex: 1" :disabled="busy" @click="emit('confirm')">{{ yes }}</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.btn.red { background: var(--hot); color: #200606; }
.btn.red:hover { box-shadow: 0 10px 26px -10px rgba(255, 107, 107, 0.6); }
</style>
