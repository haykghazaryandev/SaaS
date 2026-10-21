<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

// A small emoji picker for chat: football first, then everyday reactions. Plain Unicode emojis (free, no images).
// <EmojiPicker @pick="insert" />
const emit = defineEmits<{ pick: [emoji: string] }>();
const props = defineProps<{ label?: string; footballLabel?: string; reactionsLabel?: string }>();

const FOOTBALL = ['⚽', '🥅', '🏟️', '👟', '🧤', '🎽', '🏆', '🥇', '🥈', '🥉', '🟨', '🟥', '📣', '⏱️', '🏃', '🤸', '🧊', '🩹', '💦', '🌧️', '🌙', '🔦', '🍕', '🍻'];
const REACTIONS = ['👍', '👎', '👏', '🙌', '💪', '🔥', '😂', '🤣', '😅', '😎', '😍', '🥳', '🤝', '🙏', '🤔', '😮', '😢', '😡', '😴', '❤️', '✅', '❌', '⏰', '📍', '🚗', '🙋', '👋', '💯'];

const open = ref(false);
const root = ref<HTMLElement>();
function pick(e: string) {
    emit('pick', e);
}
function onDoc(e: MouseEvent) {
    if (open.value && root.value && !root.value.contains(e.target as Node)) open.value = false;
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') open.value = false;
}
onMounted(() => {
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDoc);
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <div ref="root" class="emo">
        <button type="button" class="emo-btn" :aria-expanded="open" :aria-label="props.label ?? 'Emoji'" @click="open = !open">🙂</button>
        <div v-if="open" class="emo-pop" role="dialog" :aria-label="props.label ?? 'Emoji'">
            <div class="emo-h">{{ props.footballLabel ?? '⚽' }}</div>
            <div class="emo-grid">
                <button v-for="e in FOOTBALL" :key="e" type="button" @click="pick(e)">{{ e }}</button>
            </div>
            <div class="emo-h">{{ props.reactionsLabel ?? '🙂' }}</div>
            <div class="emo-grid">
                <button v-for="e in REACTIONS" :key="e" type="button" @click="pick(e)">{{ e }}</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.emo { position: relative; flex: none; align-self: flex-end; }
.emo-btn { width: 40px; height: 40px; border-radius: 50%; border: 0; background: transparent; font-size: 22px; line-height: 1; display: grid; place-items: center; cursor: pointer; filter: grayscale(0.2); }
.emo-btn:hover, .emo-btn[aria-expanded='true'] { background: var(--surface-2); filter: none; }
.emo-pop { position: absolute; bottom: calc(100% + 8px); left: 0; z-index: 30; width: min(340px, calc(100vw - 24px)); max-height: 300px; overflow-y: auto; background: var(--surface-2); border: 1px solid var(--line-2); border-radius: 16px; padding: 10px; box-shadow: var(--shadow); }
.emo-h { font-size: 12px; font-weight: 800; color: var(--muted); margin: 4px 4px 6px; }
.emo-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 2px; margin-bottom: 6px; }
.emo-grid button { border: 0; background: none; font-size: 22px; line-height: 1; padding: 6px 0; border-radius: 10px; cursor: pointer; }
.emo-grid button:hover, .emo-grid button:focus-visible { background: var(--surface-3); }
</style>
