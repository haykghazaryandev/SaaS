<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import { api, type ChatItem, type ChatMessage, chatIcon, chatTitle, clock, sysText, userName } from '@/composables/useChat';
import { useOwner } from '@/composables/useOwner';
import { useShared } from '@/composables/useShared';
import { useLive } from '@/lib/echo';
import { trans } from '@/lib/i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// The owner panel's mini chat (computers only): a round button at the bottom right with the unread number; it opens a
// small window with the chats (players writing to the place, tournament chats) and a conversation, so the staff can
// answer while they book. Live: the user's channel (a chat changed) and the open chat's channel (new messages).
const owner = useOwner();
const { user } = useShared();
const me = computed(() => user.value?.id ?? 0);
const wide = ref(false);
let mq: MediaQueryList | undefined;
const onMq = () => (wide.value = !!mq?.matches);

const read = (k: string) => {
    try {
        return localStorage.getItem(k);
    } catch {
        return null;
    }
};
const save = (k: string, v: string | null) => {
    try {
        if (v === null) localStorage.removeItem(k);
        else localStorage.setItem(k, v);
    } catch {
        /* private mode */
    }
};

const open = ref(read('bb.dock.open') === '1');
const onChats = computed(() => {
    try {
        return !!route().current('owner.messages') || !!route().current('owner.messages.*');
    } catch {
        return false;
    }
});
const chats = ref<ChatItem[]>([]);
const current = ref<ChatItem | null>(null);
const msgs = ref<ChatMessage[]>([]);
const text = ref('');
const sending = ref(false);
const error = ref('');
const loading = ref(false);
// the dock comes back on every page: until its list is loaded, the number the page already has (no blink to 0)
const loaded = ref(false);
const unread = computed(() => (loaded.value ? chats.value.filter((c) => !c.muted).reduce((n, c) => n + c.unread, 0) : (owner.value.unreadChats ?? 0)));

async function loadList() {
    try {
        const r = await api<{ chats: ChatItem[] }>('GET', route('messages.list', { venue: owner.value.venue.id }));
        chats.value = r.chats;
        loaded.value = true;
        if (current.value) current.value = r.chats.find((c) => c.id === current.value!.id) ?? current.value;
    } catch {
        /* offline: keep the old list */
    }
}
let listT: number | undefined;
const soon = () => {
    clearTimeout(listT);
    listT = window.setTimeout(loadList, 400);
};

// ---------- one chat ----------
const body = ref<HTMLElement>();
const lastId = () => msgs.value.reduce((n, m) => Math.max(n, m.id), 0);
async function toEnd() {
    await nextTick();
    if (body.value) body.value.scrollTop = body.value.scrollHeight;
}
function markRead() {
    const id = lastId();
    if (current.value && id) api('POST', route('messages.read', current.value.id), { id }).then(soon).catch(() => {});
}
async function openChat(c: ChatItem) {
    leaveChannel();
    current.value = c;
    msgs.value = [];
    loading.value = true;
    save('bb.dock.chat', String(c.id));
    try {
        const r = await api<{ messages: ChatMessage[] }>('GET', route('messages.history', c.id));
        msgs.value = r.messages;
        await toEnd();
        markRead();
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
    joinChannel();
}
function back() {
    leaveChannel();
    current.value = null;
    save('bb.dock.chat', null);
    loadList();
}
function receive(m: ChatMessage) {
    const i = msgs.value.findIndex((x) => x.id === m.id);
    if (i >= 0) msgs.value[i] = m;
    else msgs.value.push(m);
    toEnd();
    markRead();
}
// the open chat, live
let channel: string | null = null;
function joinChannel() {
    if (!window.Echo || !current.value) return;
    channel = `conversation.${current.value.id}`;
    window.Echo.private(channel).listen('.message', (e: { message: ChatMessage }) => receive(e.message));
}
function leaveChannel() {
    if (channel && window.Echo) {
        try {
            window.Echo.private(channel).stopListening('.message');
        } catch {
            /* gone */
        }
    }
    channel = null;
}
async function send() {
    const b = text.value.trim();
    if (!b || !current.value || sending.value) return;
    sending.value = true;
    error.value = '';
    try {
        const r = await api<{ message: ChatMessage }>('POST', route('messages.send', current.value.id), { body: b });
        receive(r.message);
        text.value = '';
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        sending.value = false;
    }
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        send();
    }
}
function preview(c: ChatItem): string {
    const m = c.last;
    if (!m) return '';
    if (m.type === 'system') return sysText(m.body, m.meta);
    const who = m.user?.id === me.value ? trans('messages.you') + ': ' : c.type !== 'direct' ? userName(m.user) + ': ' : '';
    return m.deleted ? trans('messages.deleted') : who + (m.body ?? '');
}

function toggle() {
    open.value = !open.value;
    save('bb.dock.open', open.value ? '1' : '0');
    if (open.value) loadList();
}
// the list (and the unread number) follows every chat change
useLive(() => (me.value ? `user.${me.value}` : null), 'chat.updated', soon, true);
onMounted(async () => {
    mq = window.matchMedia('(min-width: 1000px)');
    onMq();
    mq.addEventListener('change', onMq);
    await loadList();
    const last = Number(read('bb.dock.chat'));
    const c = last ? chats.value.find((x) => x.id === last) : null;
    if (open.value && c) openChat(c);
});
watch(open, (o) => !o && leaveChannel());
onBeforeUnmount(() => {
    mq?.removeEventListener('change', onMq);
    leaveChannel();
    clearTimeout(listT);
});
</script>

<template>
    <!-- not on the Chats page itself -->
    <div v-if="wide && user && !onChats" class="dock" data-test="chat-dock">
        <section v-if="open" class="dwin" role="dialog" :aria-label="$t('owner.nav.messages')">
            <!-- the chats -->
            <template v-if="!current">
                <header class="dh">
                    <b>{{ $t('owner.nav.messages') }}</b>
                    <a class="dx" :href="route('owner.messages')" :title="$t('owner.dock.full')"><Icon name="share" size="sm" /></a>
                    <button type="button" class="dx" :aria-label="$t('common.close')" @click="toggle"><Icon name="x" size="sm" /></button>
                </header>
                <div class="dlist">
                    <button v-for="c in chats" :key="c.id" type="button" :class="['drow', { un: c.unread && !c.muted }]" :data-test="`dock-chat-${c.id}`" @click="openChat(c)">
                        <span v-if="c.type !== 'direct'" class="dav"><Icon :name="chatIcon(c)" size="sm" /></span>
                        <Avatar v-else :id="c.other?.id ?? null" :name="c.other?.name" :src="c.other?.avatar" size="sm" />
                        <span class="dw"
                            ><b>{{ chatTitle(c) }}</b><small>{{ preview(c) }}</small></span
                        >
                        <span v-if="c.unread" :class="['dun', { mute: c.muted }]">{{ c.unread > 99 ? '99+' : c.unread }}</span>
                    </button>
                    <p v-if="!chats.length" class="dnone">{{ $t('messages.empty') }}</p>
                </div>
            </template>
            <!-- one chat -->
            <template v-else>
                <header class="dh">
                    <button type="button" class="dx" :aria-label="$t('messages.back')" @click="back"><Icon name="back" size="sm" /></button>
                    <b class="dt">{{ chatTitle(current) }}</b>
                    <a class="dx" :href="route('owner.messages.show', current.id)" :title="$t('owner.dock.full')"><Icon name="share" size="sm" /></a>
                    <button type="button" class="dx" :aria-label="$t('common.close')" @click="toggle"><Icon name="x" size="sm" /></button>
                </header>
                <div ref="body" class="dbody">
                    <p v-if="loading" class="dnone">…</p>
                    <template v-for="(m, i) in msgs" :key="m.id">
                        <div v-if="m.type === 'system'" class="dsys">{{ sysText(m.body, m.meta) }}</div>
                        <div v-else :class="['dmsg', { mine: m.user?.id === me }]">
                            <small v-if="m.user?.id !== me && current.type !== 'direct' && msgs[i - 1]?.user?.id !== m.user?.id" class="dn">{{ userName(m.user) }}</small>
                            <span class="db">{{ m.deleted ? $t('messages.deleted') : m.body }}<i>{{ clock(m.created_at) }}</i></span>
                        </div>
                    </template>
                </div>
                <form v-if="!current.archived" class="dcomp" @submit.prevent="send">
                    <textarea v-model="text" rows="1" :placeholder="$t('messages.placeholder')" data-test="dock-input" @keydown="onKey" />
                    <button class="dsend" :disabled="!text.trim() || sending" :aria-label="$t('common.send')"><Icon name="send" size="sm" /></button>
                </form>
                <p v-if="error" class="derr">{{ error }}</p>
            </template>
        </section>
        <button type="button" :class="['dbtn', { on: open }]" :aria-label="$t('owner.nav.messages')" data-test="dock-button" @click="toggle">
            <Icon :name="open ? 'x' : 'chat'" />
            <span v-if="unread && !open" class="dbadge">{{ unread > 99 ? '99+' : unread }}</span>
        </button>
    </div>
</template>

<style scoped>
.dock { position: fixed; right: 22px; bottom: 22px; z-index: 60; display: flex; flex-direction: column; align-items: flex-end; gap: 12px; }
.dbtn { position: relative; width: 56px; height: 56px; border-radius: 50%; border: 0; background: var(--grad); color: var(--on-accent); display: grid; place-items: center; cursor: pointer; box-shadow: 0 14px 30px -12px rgba(0, 0, 0, 0.9), 0 6px 18px -8px rgba(200, 255, 61, 0.5); }
.dbtn.on { background: var(--surface-3); color: var(--ink); }
.dbadge { position: absolute; top: -4px; right: -4px; min-width: 22px; height: 22px; padding: 0 6px; border-radius: 99px; background: var(--hot); color: #fff; font: 800 11.5px var(--body); display: grid; place-items: center; border: 2px solid var(--bg); }
.dwin { width: 370px; height: min(560px, calc(100vh - 120px)); background: var(--surface); border: 1px solid var(--line-2); border-radius: 20px; box-shadow: 0 30px 70px -25px rgba(0, 0, 0, 0.95); display: flex; flex-direction: column; overflow: hidden; }
.dh { display: flex; align-items: center; gap: 6px; padding: 10px 10px 10px 16px; border-bottom: 1px solid var(--line); background: var(--bg-2); }
.dh b { flex: 1; min-width: 0; font: 700 15px var(--display); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dh .dt { font: 800 14px var(--body); }
.dx { width: 32px; height: 32px; border-radius: 50%; border: 0; background: none; color: var(--muted); display: grid; place-items: center; cursor: pointer; flex: none; }
.dx:hover { background: var(--surface-2); color: var(--ink); }
.dlist { flex: 1; overflow-y: auto; padding: 6px; }
.drow { width: 100%; display: flex; align-items: center; gap: 10px; padding: 9px 10px; border: 0; background: none; border-radius: 12px; color: var(--ink); font: inherit; text-align: left; cursor: pointer; }
.drow:hover { background: var(--surface-2); }
.dav { width: 30px; height: 30px; flex: none; border-radius: 50%; display: grid; place-items: center; background: rgba(94, 240, 168, 0.12); color: var(--accent-2); }
.dw { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.dw b { font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dw small { color: var(--muted); font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.drow.un .dw small { color: var(--ink); }
.dun { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 99px; background: var(--accent); color: var(--on-accent); font: 800 11px var(--body); display: grid; place-items: center; flex: none; }
.dun.mute { background: var(--surface-3); color: var(--muted); }
.dnone { color: var(--faint); font-size: 13px; text-align: center; margin: 18px 0; }
.dbody { flex: 1; overflow-y: auto; padding: 10px 12px; display: flex; flex-direction: column; gap: 6px; }
.dsys { align-self: center; font-size: 11.5px; color: var(--muted); border: 1px dashed var(--line-2); border-radius: 999px; padding: 3px 10px; text-align: center; }
.dmsg { display: flex; flex-direction: column; align-items: flex-start; max-width: 85%; }
.dmsg.mine { align-self: flex-end; align-items: flex-end; }
.dn { font-size: 11px; font-weight: 700; color: var(--accent-2); margin: 0 0 2px 8px; }
.db { background: var(--surface-2); border-radius: 14px; padding: 7px 10px; font-size: 13.5px; line-height: 1.35; white-space: pre-wrap; overflow-wrap: anywhere; }
.dmsg.mine .db { background: rgba(200, 255, 61, 0.14); border: 1px solid rgba(200, 255, 61, 0.25); }
.db i { font-style: normal; font: 600 10px var(--mono); color: var(--faint); margin-left: 8px; }
.dcomp { display: flex; gap: 8px; padding: 10px; border-top: 1px solid var(--line); }
.dcomp textarea { flex: 1; min-width: 0; resize: none; background: var(--bg-2); border: 1px solid var(--line-2); border-radius: 16px; padding: 8px 12px; color: var(--ink); font: inherit; font-size: 14px; max-height: 110px; outline: none; }
@media (max-width: 900px), (pointer: coarse) { .dcomp textarea { font-size: 16px; } }
.dcomp textarea:focus { border-color: rgba(200, 255, 61, 0.5); }
.dsend { width: 38px; height: 38px; border-radius: 50%; border: 0; background: var(--grad); color: var(--on-accent); display: grid; place-items: center; cursor: pointer; flex: none; align-self: flex-end; }
.dsend:disabled { opacity: 0.4; cursor: default; }
.derr { color: var(--hot); font-size: 12px; margin: 0 12px 8px; }
</style>
