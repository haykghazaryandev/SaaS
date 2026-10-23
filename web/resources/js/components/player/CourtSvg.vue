<script setup lang="ts">
import { computed } from 'vue';

// Top view of a football field (ported from the prototype): small = 5×5, big = full 11×11 pitch.
const props = defineProps<{ size: string; color?: string }>();

function small(c = '#2f8f4e') {
    let stripes = '';
    for (let i = 1; i < 10; i += 2) stripes += `<rect x="${i * 40}" y="0" width="40" height="250" fill="#000" opacity=".07"/>`;
    return `<rect width="400" height="250" rx="6" fill="${c}"/>${stripes}
        <g fill="none" stroke="#f2fff4" stroke-width="3" opacity=".9"><rect x="14" y="14" width="372" height="222" rx="2"/><path d="M200 14v222"/><circle cx="200" cy="125" r="34"/>
        <path d="M14 70h46v110H14M386 70h-46v110h46"/><path d="M14 100h-8v50h8M386 100h8v50h-8"/></g><circle cx="200" cy="125" r="4" fill="#f2fff4"/>`;
}
function big(c = '#2a8a48') {
    let stripes = '';
    for (let i = 1; i < 12; i += 2) stripes += `<rect x="${i * 35}" y="0" width="35" height="272" fill="#000" opacity=".07"/>`;
    return `<rect width="420" height="272" rx="6" fill="${c}"/>${stripes}
      <g fill="none" stroke="#f2fff4" stroke-width="2.6" opacity=".92"><rect x="12" y="12" width="396" height="248"/><path d="M210 12v248"/><circle cx="210" cy="136" r="35"/>
      <path d="M12 72h63v128H12M408 72h-63v128h63"/><path d="M12 108h21v56H12M408 108h-21v56h21"/><path d="M75 114a28 28 0 0 1 0 44M345 114a28 28 0 0 0 0 44"/>
      <path d="M12 122h-7v28h7M408 122h7v28h-7"/></g><g fill="#f2fff4"><circle cx="210" cy="136" r="3.5"/><circle cx="54" cy="136" r="2.5"/><circle cx="366" cy="136" r="2.5"/></g>`;
}
const isBig = computed(() => props.size === 'big');
const inner = computed(() => (isBig.value ? big(props.color) : small(props.color)));
</script>

<template>
    <svg :viewBox="isBig ? '0 0 420 272' : '0 0 400 250'" aria-hidden="true" v-html="inner" />
</template>
