<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import PlayerRow from '@/components/social/PlayerRow.vue';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { PlayerCard } from '@/types/social';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Friends: a segmented Friends | Requests list with a search field, and "+ Find players"
// (search by name / @username / phone, with "Played with you" below while nothing is typed).
// Rows are compact (64px): tap a row for the profile; friends have a chat icon button.
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

// "played" is part of Find players now (old links keep working)
const tab = ref<'friends' | 'requests' | 'find'>(props.tab === 'played' ? 'find' : props.tab);
const q = ref(props.q);
const filter = ref('');
const searching = ref(false);
const busy = ref<number | null>(null);
const searchEl = ref<HTMLInputElement>();
const canChat = (() => {
    try {
        return route().has('messages.direct');
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
function setTab(t: 'friends' | 'requests' | 'find') {
    tab.value = t;
    setUrl();
    if (t === 'find') nextTick(() => searchEl.value?.focus());
}
onMounted(() => {
    if (tab.value === 'find' && !q.value) searchEl.value?.focus();
});

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

// local filter of my friends / requests
const match = (p: PlayerCard) => {
    const s = filter.value.trim().toLowerCase().replace(/^@/, '');
    return !s || p.name.toLowerCase().includes(s) || (p.username ?? '').toLowerCase().includes(s);
};
const friendsShown = computed(() => props.friends.filter(match));
const receivedShown = computed(() => props.received.filter(match));
const sentShown = computed(() => props.sent.filter(match));

// friend actions
const opts = (id: number) => ({ preserveScroll: true, preserveState: true, onStart: () => (busy.value = id), onFinish: () => (busy.value = null) });
const add = (p: PlayerCard) => router.post(route('friends.request', p.id), {}, opts(p.id));
const accept = (p: PlayerCard) => router.post(route('friends.accept', p.id), {}, opts(p.id));
const remove = (p: PlayerCard) => router.delete(route('friends.remove', p.id), opts(p.id));
</script>

<template>
    <PlayerLayout>
        <Head :title="tab === 'find' ? $t('friends.findTitle') : $t('friends.title')" />

        <section class="fhead">
            <template v-if="tab === 'find'">
                <button type="button" class="back" @click="setTab('friends')"><Icon name="back" size="sm" />{{ $t('friends.title') }}</button>
                <h1 class="page-title">{{ $t('friends.findTitle') }}</h1>
            </template>
            <template v-else>
                <h1 class="page-title">{{ $t('friends.title') }}</h1>
                <button type="button" class="btn sm" @click="setTab('find')"><Icon name="plus" size="sm" />{{ $t('friends.findBtn') }}</button>
            </template>
        </section>

        <!-- friends | requests -->
        <template v-if="tab !== 'find'">
            <div class="seg" role="tablist">
                <button role="tab" :aria-selected="tab === 'friends'" @click="setTab('friends')">{{ $t('friends.tab.friends') }}<span class="n">{{ friends.length }}</span></button>
                <button role="tab" :aria-selected="tab === 'requests'" @click="setTab('requests')">
                    {{ $t('friends.tab.requests') }}<span v-if="received.length" class="badge-dot">{{ received.length }}</span><span v-else-if="sent.length" class="n">{{ sent.length }}</span>
                </button>
            </div>
            <label v-if="friends.length + received.length + sent.length > 0" class="search">
                <Icon name="search" />
                <input v-model="filter" type="search" class="input" :placeholder="$t('friends.filter')" :aria-label="$t('friends.filter')" autocomplete="off" autocapitalize="off" />
            </label>
        </template>

        <section v-if="tab === 'friends'">
            <div class="list">
                <PlayerRow v-for="p in friendsShown" :key="p.id" :player="p">
                    <Link v-if="canChat" :href="route('messages.direct', p.id)" method="post" as="button" class="btn icon sm" :aria-label="$t('friends.messageTo', { name: p.name })" :title="$t('friends.message')"><Icon name="chat" size="sm" /></Link>
                </PlayerRow>
            </div>
            <p v-if="friends.length && !friendsShown.length" class="muted hint">{{ $t('friends.noMatch') }}</p>
            <div v-if="!friends.length" class="empty-state">
                <span class="es-ico"><Icon name="users" /></span>
                <h3>{{ $t('friends.empty.friendsTitle') }}</h3>
                <p>{{ $t('friends.empty.friends') }}</p>
                <button type="button" class="btn sm" @click="setTab('find')"><Icon name="search" size="sm" />{{ $t('friends.findBtn') }}</button>
            </div>
        </section>

        <section v-else-if="tab === 'requests'">
            <template v-if="receivedShown.length">
                <h2 class="sub">{{ $t('friends.received') }}</h2>
                <div class="list">
                    <PlayerRow v-for="p in receivedShown" :key="p.id" :player="p">
                        <button type="button" class="btn outline sm" :disabled="busy === p.id" @click="remove(p)">{{ $t('friends.decline') }}</button>
                        <button type="button" class="btn sm" :disabled="busy === p.id" @click="accept(p)">{{ $t('friends.accept') }}</button>
                    </PlayerRow>
                </div>
            </template>
            <template v-if="sentShown.length">
                <h2 class="sub">{{ $t('friends.sent') }}</h2>
                <div class="list">
                    <PlayerRow v-for="p in sentShown" :key="p.id" :player="p">
                        <span class="state">{{ $t('friends.requested') }}</span>
                        <button type="button" class="btn icon sm" :disabled="busy === p.id" :title="$t('friends.cancel')" :aria-label="$t('friends.cancel')" @click="remove(p)"><Icon name="x" size="sm" /></button>
                    </PlayerRow>
                </div>
            </template>
            <div v-if="!received.length && !sent.length" class="empty-state">
                <span class="es-ico"><Icon name="bell" /></span>
                <p>{{ $t('friends.empty.requests') }}</p>
            </div>
        </section>

        <!-- find players (+ played with you) -->
        <section v-else>
            <label class="search big">
                <Icon name="search" />
                <input ref="searchEl" v-model="q" type="search" class="input" :placeholder="$t('friends.search')" :aria-label="$t('friends.search')" autocomplete="off" autocapitalize="off" maxlength="60" />
                <span v-if="searching" class="spin" aria-hidden="true" />
            </label>
            <template v-if="short">
                <p class="muted hint">{{ $t('friends.searchHint') }}</p>
                <h2 class="sub">{{ $t('friends.tab.played') }}</h2>
                <div v-if="played.length" class="list">
                    <PlayerRow v-for="p in played" :key="p.id" :player="p">
                        <template #extra><span class="together">{{ p.username ? ' · ' : '' }}{{ $tChoice('friends.together', p.games ?? 1, { n: String(p.games ?? 1) }) }}</span></template>
                        <button v-if="p.relation === 'none'" type="button" class="btn sm" :disabled="busy === p.id" @click="add(p)"><Icon name="plus" size="sm" />{{ $t('friends.add') }}</button>
                        <button v-else-if="p.relation === 'received'" type="button" class="btn sm" :disabled="busy === p.id" @click="accept(p)">{{ $t('friends.accept') }}</button>
                        <span v-else-if="p.relation === 'sent'" class="state">{{ $t('friends.requested') }}</span>
                    </PlayerRow>
                </div>
                <p v-else class="muted hint">{{ $t('friends.empty.played') }}</p>
            </template>
            <template v-else>
                <div class="list">
                    <PlayerRow v-for="p in results" :key="p.id" :player="p">
                        <button v-if="p.relation === 'none'" type="button" class="btn sm" :disabled="busy === p.id" @click="add(p)"><Icon name="plus" size="sm" />{{ $t('friends.add') }}</button>
                        <button v-else-if="p.relation === 'received'" type="button" class="btn sm" :disabled="busy === p.id" @click="accept(p)">{{ $t('friends.accept') }}</button>
                        <span v-else-if="p.relation === 'sent'" class="state">{{ $t('friends.requested') }}</span>
                        <Link v-else-if="p.relation === 'friends' && canChat" :href="route('messages.direct', p.id)" method="post" as="button" class="btn icon sm" :aria-label="$t('friends.messageTo', { name: p.name })"><Icon name="chat" size="sm" /></Link>
                    </PlayerRow>
                </div>
                <div v-if="!results.length && !searching" class="empty-state plain"><p>{{ $t('friends.noResults') }}</p></div>
            </template>
        </section>
    </PlayerLayout>
</template>

<style scoped>
.fhead { margin: 26px 0 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.fhead .back { flex-basis: 100%; display: inline-flex; gap: 6px; align-items: center; background: none; border: 0; padding: 0; color: var(--muted); font-weight: 700; font-size: 14px; width: max-content; }
.fhead .back:hover { color: var(--ink); }
.seg { margin-bottom: 12px; }
.seg .n { font-size: 12px; color: var(--muted); font-weight: 700; }
.list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
@media (max-width: 760px) { .list { grid-template-columns: minmax(0, 1fr); } }
.sub { font-family: var(--body); font-size: 14px; font-weight: 700; color: var(--muted); letter-spacing: 0; margin: 18px 0 8px; }
.search { position: relative; display: block; margin-bottom: 12px; max-width: 520px; }
.search > .ico { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none; }
.search .input { padding-left: 40px; }
.search.big { max-width: none; }
.search.big .input { height: 48px; font-size: 15px; border-radius: 14px; }
.spin { position: absolute; right: 14px; top: 50%; width: 16px; height: 16px; margin-top: -8px; border: 2px solid var(--line-2); border-top-color: var(--accent); border-radius: 50%; animation: sp 0.7s linear infinite; }
@keyframes sp { to { transform: rotate(360deg); } }
.hint { margin: 0 0 6px; font-size: 13.5px; }
.state { font-size: 12.5px; font-weight: 700; color: var(--muted); }
.together { color: var(--accent); font-weight: 700; }
@media (max-width: 600px) { .fhead { margin-top: 18px; } .seg { display: flex; width: 100%; } }
</style>
