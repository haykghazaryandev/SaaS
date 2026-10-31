<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { api } from '@/composables/useChat';
import { useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

// Report a player, a message or a game: POST /reports {target_type, target_id, reason}. A message shows its text
// (that is what the admin sees too); json: sent without reloading the page (the chat), then "sent".
const props = defineProps<{ targetType: 'user' | 'message' | 'game'; targetId: number; name?: string; quote?: string | null; json?: boolean }>();
const emit = defineEmits<{ close: []; sent: [] }>();
const form = useForm({ target_type: props.targetType, target_id: props.targetId, reason: '' });
const jsonErr = ref('');
async function send() {
    if (!props.json) {
        form.post(route('reports.store'), { preserveScroll: true, onSuccess: () => emit('close') });
        return;
    }
    form.processing = true;
    jsonErr.value = '';
    try {
        await api('POST', route('reports.store'), { target_type: props.targetType, target_id: props.targetId, reason: form.reason.trim() });
        emit('sent');
        emit('close');
    } catch (e) {
        jsonErr.value = (e as Error).message;
    } finally {
        form.processing = false;
    }
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
                    <h3 id="rs-t" style="margin: 0; overflow-wrap: anywhere">{{ $t('friends.report_sheet.title') }}<template v-if="name">: {{ name }}</template></h3>
                    <button type="button" class="x" :aria-label="$t('common.close')" @click="emit('close')"><Icon name="x" /></button>
                </div>
                <p class="muted" style="margin: 8px 0 14px; font-size: 14px">{{ $t('friends.report_sheet.sub') }}</p>
                <!-- the message reported -->
                <blockquote v-if="quote" class="rquote" data-test="report-quote">{{ quote }}</blockquote>
                <div class="field">
                    <label for="rs-r">{{ $t('friends.report_sheet.reason') }}</label>
                    <textarea id="rs-r" v-model="form.reason" class="input" rows="4" maxlength="500" :placeholder="$t('friends.report_sheet.placeholder')" required minlength="3" />
                    <div v-if="form.errors.reason || form.errors.target_id || jsonErr" class="err">{{ form.errors.reason || form.errors.target_id || jsonErr }}</div>
                </div>
                <div class="row" style="margin-top: 16px">
                    <button type="button" class="btn ghost" style="flex: 1" @click="emit('close')">{{ $t('common.cancel') }}</button>
                    <button class="btn" style="flex: 1" :disabled="form.processing || form.reason.trim().length < 3">{{ $t('friends.report_sheet.send') }}</button>
                </div>
            </form>
        </div>
    </Teleport>
</template>

<style scoped>
textarea.input { resize: vertical; min-height: 96px; }
.err { color: var(--hot); font-size: 13px; }
.rquote { margin: 0 0 14px; padding: 10px 12px; border-left: 3px solid var(--hot); border-radius: 10px; background: var(--surface-2); color: var(--ink); font-size: 14px; white-space: pre-wrap; overflow-wrap: anywhere; max-height: 140px; overflow: auto; }
</style>
