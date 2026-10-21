<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

// Report a player, a message or a game: POST /reports {target_type, target_id, reason}.
const props = defineProps<{ targetType: 'user' | 'message' | 'game'; targetId: number; name?: string }>();
const emit = defineEmits<{ close: [] }>();
const form = useForm({ target_type: props.targetType, target_id: props.targetId, reason: '' });
function send() {
    form.post(route('reports.store'), { preserveScroll: true, onSuccess: () => emit('close') });
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('close');
}
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div class="scrim" @click.self="emit('close')">
            <form class="sheet" role="dialog" aria-modal="true" aria-labelledby="rs-t" @submit.prevent="send">
                <div class="row" style="align-items: flex-start">
                    <h3 id="rs-t" style="margin: 0; overflow-wrap: anywhere">{{ $t('friends.reportSheet.title') }}<template v-if="name">: {{ name }}</template></h3>
                    <button type="button" class="x" :aria-label="$t('player.close')" @click="emit('close')"><Icon name="x" /></button>
                </div>
                <p class="muted" style="margin: 8px 0 14px; font-size: 14px">{{ $t('friends.reportSheet.sub') }}</p>
                <div class="field">
                    <label for="rs-r">{{ $t('friends.reportSheet.reason') }}</label>
                    <textarea id="rs-r" v-model="form.reason" class="input" rows="4" maxlength="500" :placeholder="$t('friends.reportSheet.placeholder')" required minlength="3" />
                    <div v-if="form.errors.reason || form.errors.target_id" class="err">{{ form.errors.reason || form.errors.target_id }}</div>
                </div>
                <div class="row" style="margin-top: 16px">
                    <button type="button" class="btn ghost" style="flex: 1" @click="emit('close')">{{ $t('friends.cancelBtn') }}</button>
                    <button class="btn" style="flex: 1" :disabled="form.processing || form.reason.trim().length < 3">{{ $t('friends.reportSheet.send') }}</button>
                </div>
            </form>
        </div>
    </Teleport>
</template>

<style scoped>
textarea.input { resize: vertical; min-height: 96px; }
.err { color: var(--hot); font-size: 13px; }
</style>
