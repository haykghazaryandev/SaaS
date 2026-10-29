<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

// Pages of a long list (App\Support\Paged): ‹ 1 … 4 5 6 … 12 › ; keeps the other filters of the address, scrolls to
// the list's top. Hidden when everything fits on one page.
const props = defineProps<{ page: number; pages: number; total?: number; param?: string; only?: string[] }>();
const nums = computed(() => {
    const out: (number | '…')[] = [];
    for (let i = 1; i <= props.pages; i++) {
        if (i === 1 || i === props.pages || Math.abs(i - props.page) <= 1) out.push(i);
        else if (out[out.length - 1] !== '…') out.push('…');
    }
    return out;
});
function go(n: number) {
    if (n < 1 || n > props.pages || n === props.page) return;
    const q = Object.fromEntries(new URLSearchParams(window.location.search));
    router.get(window.location.pathname, { ...q, [props.param ?? 'page']: n }, { preserveState: true, preserveScroll: true, replace: true, only: props.only });
}
</script>

<template>
    <nav v-if="pages > 1" class="pager" :aria-label="$t('common.pages')">
        <button type="button" class="pg" :disabled="page <= 1" :aria-label="$t('common.prev')" @click="go(page - 1)">‹</button>
        <template v-for="(n, i) in nums" :key="i">
            <span v-if="n === '…'" class="pgd">…</span>
            <button v-else type="button" :class="['pg', { on: n === page }]" :aria-current="n === page ? 'page' : undefined" @click="go(n)">{{ n }}</button>
        </template>
        <button type="button" class="pg" :disabled="page >= pages" :aria-label="$t('common.next')" @click="go(page + 1)">›</button>
        <small v-if="total != null" class="pgt">{{ $t('common.rows_total', { n: total }) }}</small>
    </nav>
</template>

<style scoped>
.pager { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 12px; }
.pg { min-width: 36px; height: 36px; padding: 0 10px; border-radius: 10px; border: 1px solid var(--line-2); background: var(--surface-2); color: var(--ink); font: 700 13.5px var(--body); cursor: pointer; }
.pg.on { background: var(--accent); color: #0b0f0c; border-color: transparent; }
.pg:disabled { opacity: 0.35; cursor: default; }
.pgd { color: var(--faint); padding: 0 2px; }
.pgt { margin-left: auto; color: var(--muted); font-size: 12.5px; }
</style>
