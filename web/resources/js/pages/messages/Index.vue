<script setup lang="ts">
import { presence } from '@/composables/useSocial';
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import { type ChatItem, chatTitle, shortWhen, sysText, userName } from '@/composables/useChat';
import { useShared } from '@/composables/useShared';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// Chats: direct and game chats with the last message; unread ones bold with a count; closed (archived) chats below.
// "New message" opens a friend picker (friends are loaded on demand) and starts the direct chat (messages.direct).
// Live: private-user.{id} ".chat.updated" reloads the list.
const props = defineProps<{ chats: ChatItem[]; archived: ChatItem[]; friends?: { id: number; name: string; username: string | null }[] }>();
const { user } = useShared();

function preview(c: ChatItem): string {
    const m = c.last;
    if (!m) return '';
    if (m.type === 'system') return sysText(m.body, m.meta);
    const who = m.user?.id === user.value?.id ? trans('messages.you') : c.type === 'game' ? userName(m.user) : '';
    return (who ? who + ': ' : '') + (m.deleted ? trans('messages.deleted') : (m.body ?? ''));
}
const hasFind = (() => {
    try {
        return route().has('friends.index');
    } catch {
        return false;
    }
})();

// ---------- "New message": friend picker ----------
const picking = ref(false);
const loadingFriends = ref(false);
const q = ref('');
const busyId = ref<number | null>(null);
function openPicker() {
    picking.value = true;
    q.value = '';
    if (!props.friends) router.reload({ only: ['friends'], onStart: () => (loadingFriends.value = true), onFinish: () => (loadingFriends.value = false) });
}
const shownFriends = computed(() => {
    const s = q.value.trim().toLowerCase().replace(/^@/, '');
    const all = props.friends ?? [];
    return s ? all.filter((f) => f.name.toLowerCase().includes(s) || (f.username ?? '').includes(s)) : all;
});
function start(id: number) {
    busyId.value = id;
    router.post(route('messages.direct', id), {}, { onFinish: () => (busyId.value = null) });
}

// ---------- live ----------
let timer: number | undefined;
let channel: string | null = null;
function refresh() {
    clearTimeout(timer);
    timer = window.setTimeout(() => router.reload({ only: ['chats', 'archived'] }), 250);
}
function listen() {
    if (!window.Echo || !user.value || channel) return;
    channel = `user.${user.value.id}`;
    window.Echo.private(channel).listen('.chat.updated', refresh);
}
let retry: number | undefined;
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') picking.value = false;
}
onMounted(() => {
    listen();
    // Echo starts right after login; try again shortly if it was not ready yet
    retry = window.setInterval(() => (channel ? clearInterval(retry) : listen()), 1000);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    clearInterval(retry);
    document.removeEventListener('keydown', onKey);
    if (channel) window.Echo?.private(channel).stopListening('.chat.updated', refresh);
});

// online: the live list of open apps once it answered, else the server's "seen in the last 2 minutes"
const isOnline = (o: { id: number; online?: boolean } | null | undefined) => (o ? (presence.live ? presence.ids.has(o.id) : !!o.online) : false);
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('messages.title')" />
        <div class="msgs">
            <header class="mhead">
                <h1 class="page-title">{{ $t('messages.title') }}</h1>
                <button type="button" class="btn sm" @click="openPicker"><Icon name="edit" size="sm" />{{ $t('messages.newMessage') }}</button>
            </header>

            <section v-if="chats.length" class="clist">
                <Link v-for="c in chats" :key="c.id" :href="route('messages.show', c.id)" :class="['list-row', 'citem', { unread: c.unread }]">
                    <span v-if="c.type === 'game'" class="cav"><Icon name="ball" /></span>
                    <span v-else class="avw">
                        <Avatar :id="c.other?.id ?? null" :name="c.other?.name" :src="c.other?.avatar" size="md" />
                        <i v-if="isOnline(c.other)" class="on-dot" role="img" :aria-label="$t('messages.online')" />
                    </span>
                    <span class="cmain">
                        <span class="crow">
                            <b class="ctitle">{{ chatTitle(c) }}</b>
                            <span class="ctime">{{ shortWhen(c.last?.created_at ?? c.at) }}</span>
                        </span>
                        <span class="crow">
                            <span :class="['cprev', { sys: c.last?.type === 'system' }]">{{ preview(c) }}</span>
                            <span v-if="c.unread" class="badge-dot" :aria-label="$t('messages.unreadN', { n: String(c.unread) })">{{ c.unread > 99 ? '99+' : c.unread }}</span>
                        </span>
                    </span>
                </Link>
            </section>
            <div v-else class="empty-state">
                <span class="es-ico"><Icon name="chat" /></span>
                <h3>{{ $t('messages.empty') }}</h3>
                <p>{{ $t('messages.emptyHint') }}</p>
                <Link v-if="hasFind" class="btn sm" :href="route('friends.index', { tab: 'find' })"><Icon name="search" size="sm" />{{ $t('messages.findPlayers') }}</Link>
            </div>

            <template v-if="archived.length">
                <h2 class="sec">{{ $t('messages.archivedTitle') }}</h2>
                <section class="clist off">
                    <Link v-for="c in archived" :key="c.id" :href="route('messages.show', c.id)" class="list-row citem">
                        <span v-if="c.type === 'game'" class="cav"><Icon name="ball" /></span>
                        <Avatar v-else :id="c.other?.id ?? null" :name="c.other?.name" :src="c.other?.avatar" size="md" />
                        <span class="cmain">
                            <span class="crow">
                                <b class="ctitle">{{ chatTitle(c) }}</b>
                                <span class="ctime">{{ shortWhen(c.last?.created_at ?? c.at) }}</span>
                            </span>
                            <span class="crow"><span class="cprev">{{ preview(c) }}</span></span>
                        </span>
                    </Link>
                </section>
            </template>
        </div>

        <!-- new message: pick a friend -->
        <Teleport to="body">
            <div v-if="picking" class="scrim" @click.self="picking = false">
                <div class="sheet psheet" role="dialog" aria-modal="true" aria-labelledby="nmt">
                    <div class="sheet-head">
                        <h3 id="nmt">{{ $t('messages.newMessage') }}</h3>
                        <button type="button" class="x" :aria-label="$t('player.close')" @click="picking = false"><Icon name="x" /></button>
                    </div>
                    <input v-if="(friends ?? []).length > 6" v-model="q" type="search" class="input" :placeholder="$t('messages.searchFriends')" :aria-label="$t('messages.searchFriends')" />
                    <p v-if="loadingFriends && !friends" class="muted">{{ $t('messages.loading') }}</p>
                    <div v-else-if="friends && !friends.length" class="empty-state plain">
                        <span class="es-ico"><Icon name="users" /></span>
                        <p>{{ $t('messages.noFriends') }}</p>
                        <Link v-if="hasFind" class="btn sm" :href="route('friends.index', { tab: 'find' })"><Icon name="search" size="sm" />{{ $t('messages.findPlayers') }}</Link>
                    </div>
                    <div v-else class="plist">
                        <button v-for="f in shownFriends" :key="f.id" type="button" class="list-row" :disabled="busyId !== null" @click="start(f.id)">
                            <Avatar :id="f.id" :name="f.name" :src="f.avatar" size="md" />
                            <span class="lr-main"><b>{{ f.name }}</b><small v-if="f.username">@{{ f.username }}</small></span>
                            <Icon name="chat" size="sm" class="faint" />
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </PlayerLayout>
</template>

<style scoped>
/* the other person has Bookball open (the live list; before it answered: seen in the last 2 minutes) */
.avw { position: relative; display: inline-grid; flex: none; }
.on-dot { position: absolute; right: -1px; bottom: -1px; width: 12px; height: 12px; border-radius: 50%; background: var(--free); border: 2px solid var(--surface); }
.msgs { max-width: 760px; margin: 0 auto; padding: 24px 0 40px; }
.mhead { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; min-width: 0; }
.mhead h1 { min-width: 0; overflow-wrap: anywhere; }
.mhead .btn { flex: none; }
.sec { font-family: var(--body); font-size: 14px; color: var(--muted); font-weight: 700; letter-spacing: 0; margin: 26px 4px 8px; }
.clist { display: flex; flex-direction: column; background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg); padding: 4px; }
.citem { gap: 12px; }
.citem + .citem { border-top: 1px solid var(--line); border-radius: 0; }
.cav { width: 40px; height: 40px; flex: none; border-radius: 50%; display: grid; place-items: center; background: var(--st-confirmed-soft); color: var(--st-confirmed); }
.cmain { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.crow { display: flex; align-items: center; gap: 10px; min-width: 0; }
.ctitle { flex: 1; min-width: 0; font-size: 15px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ctime { font-size: 12px; color: var(--muted); flex: none; font-variant-numeric: tabular-nums; }
.cprev { flex: 1; min-width: 0; color: var(--muted); font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cprev.sys { font-style: italic; }
.unread .ctitle { font-weight: 800; color: var(--ink); }
.unread .cprev { color: var(--ink); font-weight: 700; }
.unread .ctime { color: var(--accent); font-weight: 700; }
.off { opacity: 0.72; }
.psheet { display: flex; flex-direction: column; gap: 12px; }
.psheet .sheet-head { margin-bottom: 0; }
.plist { display: flex; flex-direction: column; max-height: 60vh; overflow: auto; margin: 0 -8px; }
.plist .list-row { background: none; border: 0; width: 100%; text-align: left; }
@media (max-width: 600px) {
    .msgs { padding-top: 16px; }
    .clist { margin: 0 -8px; border-radius: var(--r-md); }
}
</style>
