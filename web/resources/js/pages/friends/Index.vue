<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import FriendActions from '@/components/social/FriendActions.vue';
import PlayerRow from '@/components/social/PlayerRow.vue';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { PlayerCard } from '@/types/social';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

// Friends: my friends, requests (received + sent), find players, players I played with.
type Tab = 'friends' | 'requests' | 'find' | 'played';
const props = defineProps<{
    tab: Tab;
    q: string;
    friends: PlayerCard[];
    received: PlayerCard[];
    sent: PlayerCard[];
    played: PlayerCard[];
    results: PlayerCard[];
}>();

const tab = ref<Tab>(props.tab);
const q = ref(props.q);
const searching = ref(false);
const searchEl = ref<HTMLInputElement>();
const canGames = (() => {
    try {
        return route().has('games.index');
    } catch {
        return false;
    }
})();

function setUrl() {
    const u = new URL(window.location.href);
    if (tab.value === 'friends') u.searchParams.delete('tab');
    else u.searchParams.set('tab', tab.value);
    if (tab.value === 'find' && q.value.trim()) u.searchParams.set('q', q.value.trim());
    else u.searchParams.delete('q');
    window.history.replaceState(window.history.state, '', u.toString());
}
function setTab(t: Tab) {
    tab.value = t;
    setUrl();
    if (t === 'find') nextTick(() => searchEl.value?.focus());
}

// search as you type (min 2 characters), only the results are reloaded
let timer: number | undefined;
watch(q, (v) => {
    clearTimeout(timer);
    timer = window.setTimeout(() => {
        const term = v.trim();
        if (term.length < 2 && !props.results.length) return setUrl();
        router.get(
            route('friends.index'),
            { tab: 'find', q: term },
            { only: ['results', 'q'], preserveState: true, preserveScroll: true, replace: true, onStart: () => (searching.value = true), onFinish: () => (searching.value = false) },
        );
    }, 300);
});
onBeforeUnmount(() => clearTimeout(timer));

const short = computed(() => q.value.trim().length < 2);
const tabs = computed(() => [
    { key: 'friends' as Tab, icon: 'users', n: props.friends.length, hot: false },
    { key: 'requests' as Tab, icon: 'bell', n: props.received.length, hot: props.received.length > 0 },
    { key: 'find' as Tab, icon: 'search', n: null, hot: false },
    { key: 'played' as Tab, icon: 'ball', n: props.played.length, hot: false },
]);
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('friends.title')" />

        <section class="fhead">
            <span class="kicker">{{ $t('friends.kick') }}</span>
            <h1>{{ $t('friends.h1') }}</h1>
            <p class="muted">{{ $t('friends.lead') }}</p>
        </section>

        <div class="tabs" role="tablist">
            <button v-for="t in tabs" :key="t.key" role="tab" :aria-selected="tab === t.key" @click="setTab(t.key)">
                <Icon :name="t.icon" size="sm" />{{ $t('friends.tab.' + t.key) }}<span v-if="t.n !== null" :class="['n', { hot: t.hot }]">{{ t.n }}</span>
            </button>
        </div>

        <!-- friends -->
        <section v-if="tab === 'friends'" class="list">
            <PlayerRow v-for="p in friends" :key="p.id" :player="p"><FriendActions :player="p" message /></PlayerRow>
            <div v-if="!friends.length" class="empty">
                <p style="margin: 0 0 14px">{{ $t('friends.empty.friends') }}</p>
                <button type="button" class="btn sm" @click="setTab('find')"><Icon name="search" size="sm" />{{ $t('friends.findBtn') }}</button>
            </div>
        </section>

        <!-- requests -->
        <section v-else-if="tab === 'requests'" class="list">
            <template v-if="received.length">
                <h2 class="sub">{{ $t('friends.received') }}</h2>
                <PlayerRow v-for="p in received" :key="p.id" :player="p"><FriendActions :player="p" /></PlayerRow>
            </template>
            <template v-if="sent.length">
                <h2 class="sub">{{ $t('friends.sent') }}</h2>
                <PlayerRow v-for="p in sent" :key="p.id" :player="p"><FriendActions :player="p" /></PlayerRow>
            </template>
            <div v-if="!received.length && !sent.length" class="empty">{{ $t('friends.empty.requests') }}</div>
        </section>

        <!-- find -->
        <section v-else-if="tab === 'find'" class="list">
            <label class="search">
                <Icon name="search" />
                <input ref="searchEl" v-model="q" type="search" class="input" :placeholder="$t('friends.search')" :aria-label="$t('friends.search')" autocomplete="off" autocapitalize="off" maxlength="60" />
                <span v-if="searching" class="spin" aria-hidden="true" />
            </label>
            <p v-if="short" class="muted hint">{{ $t('friends.searchHint') }}</p>
            <template v-else>
                <PlayerRow v-for="p in results" :key="p.id" :player="p"><FriendActions :player="p" message /></PlayerRow>
                <div v-if="!results.length && !searching" class="empty">{{ $t('friends.noResults') }}</div>
            </template>
        </section>

        <!-- played with -->
        <section v-else class="list">
            <PlayerRow v-for="p in played" :key="p.id" :player="p">
                <template #extra
                    ><span class="together"><Icon name="ball" size="sm" />{{ $tChoice('friends.together', p.games ?? 1, { n: String(p.games ?? 1) }) }}</span></template
                >
                <FriendActions :player="p" />
            </PlayerRow>
            <div v-if="!played.length" class="empty">
                <p :style="{ margin: canGames ? '0 0 14px' : 0 }">{{ $t('friends.empty.played') }}</p>
                <Link v-if="canGames" class="btn sm" :href="route('games.index')"><Icon name="ball" size="sm" />{{ $t('nav.games') }}</Link>
            </div>
        </section>
    </PlayerLayout>
</template>

<style scoped>
.fhead { margin-top: 28px; }
.fhead h1 { font-size: clamp(24px, 3.4vw, 34px); margin: 4px 0 6px; }
.fhead p { margin: 0; max-width: 620px; }
.tabs { margin: 22px 0 16px; }
.tabs .n { font-size: 11.5px; background: var(--surface-3); color: var(--muted); border-radius: 99px; padding: 1px 7px; }
.tabs .n.hot, .tabs button[aria-selected="true"] .n { background: var(--accent); color: var(--on-accent); }
.list { display: flex; flex-direction: column; gap: 10px; max-width: 860px; }
.sub { font-size: 13px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); font-family: var(--mono); font-weight: 500; margin: 8px 0 2px; }
.search { position: relative; display: block; }
.search > .ico { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none; }
.search .input { padding-left: 42px; height: 48px; font-size: 15px; border-radius: 14px; }
.spin { position: absolute; right: 14px; top: 50%; width: 16px; height: 16px; margin-top: -8px; border: 2px solid var(--line-2); border-top-color: var(--accent); border-radius: 50%; animation: sp 0.7s linear infinite; }
@keyframes sp { to { transform: rotate(360deg); } }
.hint { margin: 0; font-size: 13.5px; }
.together { display: inline-flex; gap: 5px; align-items: center; color: var(--accent); font-size: 12.5px; font-weight: 700; margin-top: 2px; }
</style>
