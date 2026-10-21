<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';

// four boxes for the one-time code; emits "complete" when all four digits are in
const emit = defineEmits<{ complete: [code: string] }>();
const digits = ref(['', '', '', '']);
const boxes = ref<HTMLInputElement[]>([]);

function onInput(i: number, e: Event) {
    const v = (e.target as HTMLInputElement).value.replace(/\D/g, '');
    if (v.length > 1) {
        // pasted the whole code
        v.slice(0, 4).split('').forEach((d, k) => (digits.value[k] = d));
    } else {
        digits.value[i] = v;
        if (v && boxes.value[i + 1]) boxes.value[i + 1].focus();
    }
    const code = digits.value.join('');
    if (code.length === 4) emit('complete', code);
}
function onKey(i: number, e: KeyboardEvent) {
    if (e.key === 'Backspace' && !digits.value[i] && boxes.value[i - 1]) boxes.value[i - 1].focus();
}
function clear() {
    digits.value = ['', '', '', ''];
    nextTick(() => boxes.value[0]?.focus());
}
defineExpose({ clear });
onMounted(() => boxes.value[0]?.focus());
</script>

<template>
    <div class="code-inputs">
        <input
            v-for="(d, i) in digits"
            :key="i"
            :ref="(el) => (boxes[i] = el as HTMLInputElement)"
            :value="d"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="4"
            :aria-label="`${i + 1}`"
            @input="onInput(i, $event)"
            @keydown="onKey(i, $event)"
        />
    </div>
</template>
