<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import RequestChips from '@/components/owner/RequestChips.vue';
import { accept, askDecline, decisions, relDay, useOwner, whoOf, type BookingRow } from '@/composables/useOwner';
import { hhmm } from '@/composables/useShared';
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// The requests waiting for an answer: an icon at the top right with their number; it opens the list (who, which
// table, when, a warning if they did not come here before) with Accept / Decline. A new request: the icon shakes
// (the sound is played by the layout, once).
const props = defineProps<{ compact?: boolean }>();
const owner = useOwner();
const open = ref(false);
const list = ref<(BookingRow & { field: string })[]>([]);
const loading = ref(false);
const count = computed(() => Math.max(0, owner.value.pending - Object.keys(decisions.hidden).length));
const shown = computed(() => list.value.filter((b) => !decisions.hidden[b.id]));
const shake = ref(false);
// "same time as Hayk, Narek"
const compNames = (b: BookingRow) => (b.competing ?? []).map((id) => list.value.find((x) => x.id === id)).filter(Boolean).map((x) => x!.team || x!.name).join(', ');

async function load() {
    loading.value = true;
    try {
        const r = await fetch(route('owner.requests'), { headers: { Accept: 'application/json' } });
        if (r.ok) list.value = (await r.json()).requests;
    } finally {
        loading.value = false;
    }
}
function toggle() {
    open.value = !open.value;
    if (open.value) load();
}
watch(
    () => owner.value.pending,
    (n, old) => {
        if (n > (old ?? 0)) {
            shake.value = true;
            window.setTimeout(() => (shake.value = false), 900);
        }
        if (open.value) load();
    },
);
const el = ref<HTMLElement>();
const onDoc = (e: MouseEvent) => open.value && el.value && !el.value.contains(e.target as Node) && (open.value = false);
onMounted(() => document.addEventListener('click', onDoc));
onBeforeUnmount(() => document.removeEventListener('click', onDoc));
</script>

<template>
    <div ref="el" :class="['rbell', { compact: props.compact }]">
        <button type="button" :class="['rb', { shake, has: count }]" :aria-label="$t('owner.requests.title', { n: count })" data-test="requests-bell" @click.stop="toggle">
            <Icon name="bell" :size="compact ? 'sm' : undefined" />
            <span v-if="count" class="rbn">{{ count > 99 ? '99+' : count }}</span>
        </button>
        <div v-if="open" class="rpanel" role="dialog" :aria-label="$t('owner.requests.title', { n: count })">
            <header>
                <b>{{ $t('owner.requests.title', { n: count }) }}</b>
                <Link :href="route('owner.requests')" class="rall" @click="open = false">{{ $t('owner.requests.all_page') }}</Link>
            </header>
            <div class="rlist">
                <p v-if="loading && !shown.length" class="rnone">…</p>
                <p v-else-if="!shown.length" class="rnone">{{ $t('owner.requests.none') }}</p>
                <div v-for="b in shown" :key="b.id" :class="['ritem', { train: b.purpose === 'training' }]" :data-test="`request-${b.id}`">
                    <div class="rw">
                        <b>{{ b.team || b.name }}</b>
                        <RequestChips :b="b" style="margin: 3px 0 2px" />
                        <small v-if="b.competing?.length" class="rcomp">{{ $t('owner.requests.same_time', { names: compNames(b) }) }}</small>
                        <small v-if="b.rank === 1" class="rhow">{{ $t('owner.requests.rank_how') }}</small>
                        <small>{{ b.field }} · {{ relDay(b.date, owner.today) }} · {{ hhmm(b.start) }}–{{ hhmm(b.end) }}</small>
                    </div>
                    <span class="racts">
                        <button type="button" class="btn sm" data-test="bell-accept" @click="accept(b.id, b)">{{ $t('common.accept') }}</button>
                        <button type="button" class="btn ghost sm" @click="(open = false), askDecline(b.id, whoOf(b))">{{ $t('common.decline') }}</button>
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.rbell { position: relative; }
.rb { position: relative; width: 44px; height: 44px; border-radius: 50%; border: 1px solid var(--line-2); background: var(--surface); color: var(--muted); display: grid; place-items: center; cursor: pointer; }
.compact .rb { width: 36px; height: 36px; }
.rb.has { color: var(--warn); border-color: color-mix(in srgb, var(--warn) 45%, transparent); }
.rb.shake { animation: rshake 0.8s; }
@keyframes rshake { 15%, 45%, 75% { transform: rotate(-14deg); } 30%, 60%, 90% { transform: rotate(14deg); } }
.rbn { position: absolute; top: -5px; right: -5px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 99px; background: var(--warn); color: #1d1300; font: 800 11px var(--body); display: grid; place-items: center; border: 2px solid var(--bg); }
.rpanel { position: absolute; right: 0; top: calc(100% + 8px); z-index: 70; width: min(380px, calc(100vw - 24px)); max-height: min(520px, 70vh); display: flex; flex-direction: column; background: var(--surface); border: 1px solid var(--line-2); border-radius: 18px; box-shadow: 0 24px 60px -20px rgba(0, 0, 0, 0.9); overflow: hidden; }
.rpanel header { display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; border-bottom: 1px solid var(--line); }
.rall { font-size: 13px; font-weight: 700; color: var(--accent); text-decoration: none; }
.rlist { overflow-y: auto; padding: 6px; }
.ritem { display: flex; flex-direction: column; gap: 8px; padding: 10px; border-radius: 12px; }
.ritem + .ritem { border-top: 1px solid var(--line); }
.rw { display: flex; flex-direction: column; min-width: 0; }
.rw small { color: var(--muted); font-size: 12.5px; }
.rcomp { color: var(--warn) !important; font-weight: 700; }
.rhow { color: var(--faint) !important; font-size: 11.5px !important; }
.ritem.train { box-shadow: inset 3px 0 0 #ff7ad9; }
.rns { margin-left: 6px; font-size: 11px; font-weight: 800; color: var(--hot); }
.racts { display: flex; gap: 6px; }
@media (max-width: 600px) { .rpanel { position: fixed; left: 12px; right: 12px; top: 72px; width: auto; } }
.rnone { color: var(--faint); text-align: center; font-size: 13px; margin: 18px 0; }
</style>
