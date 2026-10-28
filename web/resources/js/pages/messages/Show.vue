<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import EmojiPicker from '@/components/EmojiPicker.vue';
import Icon from '@/components/Icon.vue';
import FriendActions from '@/components/social/FriendActions.vue';
import { ask } from '@/composables/useConfirm';
import { whenShort } from '@/composables/useGames';
import { api, type ChatItem, type ChatMessage, chatIcon, chatTitle, clock, dayKey, dayLabel, lastSeenText, sysText, userName } from '@/composables/useChat';
import { useShared } from '@/composables/useShared';
import { presence, refresh as refreshCounts } from '@/composables/useSocial';
import { echoConnected, echoState } from '@/lib/echo';
import { tgApp } from '@/lib/telegram';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { Relation } from '@/types/social';
import { Head, Link, router } from '@inertiajs/vue3';
import { trans } from '@/lib/i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// A chat screen (direct or game chat). Live over Reverb: private-conversation.{id} ".message" {action, message}
// and the "typing" whisper; when the socket is down it polls /history?after=<last id> every 5 s.
// The header shows "online" / "last seen …" (direct) or "N online" (game), refreshed from /presence every minute.
const props = defineProps<{
    chat: ChatItem & {
        muted?: boolean;
        canLeave?: boolean;
        members: number;
        lastReadId: number | null;
        online: number | null;
        reads?: Record<string, number>;
        people?: Record<string, Relation>;
    };
    messages: ChatMessage[];
    hasMore: boolean;
    editMinutes: number;
    maxLength: number;
    canReport: boolean;
}>();
const { user } = useShared();
const me = computed(() => user.value?.id ?? 0);
const cid = props.chat.id;
const archived = computed(() => props.chat.archived);

const msgs = ref<ChatMessage[]>([...props.messages]);
const more = ref(props.hasMore);
const lastId = () => msgs.value.reduce((n, m) => Math.max(n, m.id), 0);
const now = ref(Date.now());

// ---------- list: days, runs of the same author, the "unread" divider ----------
const dividerAfter = props.chat.lastReadId ?? 0;
// fixed when the chat opens: messages that arrive while reading don't move the divider
const firstUnread: number | undefined = dividerAfter ? props.messages.find((m) => m.id > dividerAfter && m.user?.id !== me.value)?.id : undefined;
type Row = { m: ChatMessage; mine: boolean; head: boolean; tail: boolean };
const groups = computed(() => {
    const out: { key: string; label: string; rows: Row[] }[] = [];
    msgs.value.forEach((m, i) => {
        const key = dayKey(m.created_at);
        if (!out.length || out[out.length - 1].key !== key) out.push({ key, label: dayLabel(key), rows: [] });
        const prev = msgs.value[i - 1];
        const next = msgs.value[i + 1];
        const same = (a?: ChatMessage, b?: ChatMessage) =>
            !!a && !!b && a.type !== 'system' && b.type !== 'system' && a.user?.id === b.user?.id && dayKey(a.created_at) === dayKey(b.created_at) && Math.abs(new Date(a.created_at).getTime() - new Date(b.created_at).getTime()) < 5 * 60e3;
        out[out.length - 1].rows.push({ m, mine: m.user?.id === me.value, head: !same(prev, m) || m.id === firstUnread, tail: !same(m, next) });
    });
    return out;
});
// a group chat (a game or a tournament): names and avatars on others' messages, "n online"
const isGame = props.chat.type !== 'direct';
// a tournament: the organiser's messages stand out
const hosts = new Set(props.chat.tournament?.hostIds ?? props.chat.venue?.hostIds ?? []);
const isHost = (m: ChatMessage) => !!m.user && hosts.has(m.user.id);
// ⋯ in the header: mute, leave a group chat that is on, delete a direct / place / finished chat (for me)
const menuOpen = ref(false);
const setMute = (on: boolean) => {
    menuOpen.value = false;
    router.post(route('messages.mute', cid), { on: on ? 1 : 0 }, { preserveScroll: true, preserveState: true });
};
async function leaveChat() {
    menuOpen.value = false;
    if (await ask({ title: trans('messages.ask_leave'), text: trans('messages.ask_leave_text'), ok: trans('messages.leave'), danger: true }))
        router.post(route('messages.leave', cid));
}
async function removeChat() {
    menuOpen.value = false;
    if (await ask({ title: trans('messages.ask_remove'), text: trans(props.chat.archived ? 'messages.ask_remove_closed' : 'messages.ask_remove_text'), ok: trans('messages.remove'), danger: true }))
        router.post(route('messages.remove', cid));
}
// tap a name or an avatar in a group chat: who it is, their profile, "Add friend"
const person = ref<{ id: number; name: string; username: string | null; avatar?: string | null } | null>(null);
const personRel = computed<Relation>(() => (person.value ? (props.chat.people?.[person.value.id] ?? 'none') : 'none'));
function openPerson(u: ChatMessage['user']) {
    if (u && u.id !== me.value) person.value = { id: u.id, name: userName(u), username: u.username ?? null, avatar: u.avatar };
}

// ---------- pinned game bar: "Thu 21:00 · Zangak · field not booked → Book" ----------
const bar = computed(() => {
    const g = props.chat.game;
    if (!g || archived.value || ['cancelled', 'played', 'archived'].includes(g.status)) return null;
    const b = g.booking ?? null;
    const when = b ? whenShort({ date: b.date, from: b.start }) : g.window ? whenShort(g.window) : trans('games.card.time_tbd');
    const where = b?.venue.name ?? g.place ?? (g.district ? trans(`common.districts.${g.district}`) : trans('games.card.anywhere'));
    const state = b ? (b.status === 'accepted' ? 'booked' : 'requested') : 'none';
    // only the creator books the field for the game
    const canBook = !b && ['open', 'full'].includes(g.status) && !!g.url && (g as { creatorId?: number }).creatorId === user.value?.id;
    return { when, where, state, canBook, url: g.url };
});
// the counter shows only near the limit
const showCount = computed(() => text.value.length > props.maxLength - 200);

// ---------- scrolling ----------
const scroller = ref<HTMLElement>();
const newBelow = ref(0);
const nearBottom = () => {
    const el = scroller.value;
    return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 140;
};
async function toBottom(smooth = false) {
    await nextTick();
    const el = scroller.value;
    if (el) el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    newBelow.value = 0;
}
function onScroll() {
    if (nearBottom()) {
        newBelow.value = 0;
        markRead();
    }
    if (scroller.value && scroller.value.scrollTop < 80 && more.value && !loadingOlder.value) loadOlder();
}

// ---------- merge messages (from the server, the socket or polling) ----------
function upsert(list: ChatMessage[]) {
    let added = 0;
    for (const m of list) {
        if (m.conversation_id !== cid) continue;
        const i = msgs.value.findIndex((x) => x.id === m.id);
        if (i >= 0) msgs.value[i] = m;
        else {
            const at = msgs.value.findIndex((x) => x.id > m.id);
            if (at < 0) msgs.value.push(m);
            else msgs.value.splice(at, 0, m);
            if (m.user?.id !== me.value) added++;
            if (m.user) delete typers.value[m.user.id];
            sawOther(m.user?.id);
        }
        // quotes of this message follow its edits / deletion
        for (const x of msgs.value) if (x.reply?.id === m.id) x.reply = { ...x.reply, body: m.body, deleted: m.deleted };
    }
    return added;
}
async function receive(list: ChatMessage[]) {
    const stick = nearBottom();
    const added = upsert(list);
    if (!added) return;
    if (stick) await toBottom(true);
    else newBelow.value += added;
    markRead();
}

// ---------- older messages ----------
const loadingOlder = ref(false);
async function loadOlder() {
    if (!more.value || loadingOlder.value || !msgs.value.length) return;
    loadingOlder.value = true;
    const el = scroller.value;
    const before = el ? el.scrollHeight - el.scrollTop : 0;
    try {
        const r = await api<{ messages: ChatMessage[]; hasMore: boolean }>('GET', route('messages.history', { conversation: cid, before: msgs.value[0].id }));
        upsert(r.messages);
        more.value = r.hasMore;
        await nextTick();
        if (el) el.scrollTop = el.scrollHeight - before; // keep the view where it was
    } catch {
        /* try again on the next scroll */
    } finally {
        loadingOlder.value = false;
    }
}

// ---------- read receipts ----------
let readSent = props.chat.lastReadId ?? 0;
let readTimer: number | undefined;
function markRead() {
    clearTimeout(readTimer);
    readTimer = window.setTimeout(() => {
        const id = lastId();
        if (document.visibilityState !== 'visible' || id <= readSent || !nearBottom()) return;
        readSent = id;
        api('POST', route('messages.read', cid), { id })
            .then(() => {
                // the unread badge goes at once, and no preloaded page keeps showing the old count
                router.flushAll();
                refreshCounts();
            })
            .catch(() => (readSent = 0));
    }, 400);
}
function onVisible() {
    if (document.visibilityState === 'visible') {
        markRead();
        refreshPresence();
        if (!echoConnected()) poll();
    }
}

// ---------- online / last seen (header) ----------
const other = ref({ online: !!props.chat.other?.online, lastSeen: props.chat.other?.lastSeen ?? null });
const onlineN = ref(props.chat.online ?? 0);
// the live list of open apps decides "online" (closing the app = offline at once); the server's value until it answered
const otherOnline = computed(() => {
    const id = props.chat.other?.id;
    return presence.live && id ? presence.ids.has(id) : other.value.online;
});
const otherSeen = computed(() => (props.chat.other?.id && presence.leftAt[props.chat.other.id]) || other.value.lastSeen);
const seenText = computed(() => lastSeenText(otherOnline.value, otherSeen.value));
// game chats: re-count online members when someone opens or closes the app
watch(
    () => presence.ids.size,
    () => isGame && setTimeout(refreshPresence, 1500),
);

// ---------- "seen" ticks on my messages: ✓ sent, ✓✓ read (direct: by them; game: by at least one) ----------
const reads = ref<Record<string, number>>({ ...(props.chat.reads ?? {}) });
const seenBy = (id: number) => Object.values(reads.value).filter((r) => r >= id).length;
async function refreshPresence() {
    if (document.visibilityState !== 'visible') return;
    try {
        const r = await api<{ other: { online: boolean; lastSeen: string | null } | null; online: number | null }>('GET', route('messages.presence', cid));
        if (r.other) other.value = r.other;
        onlineN.value = r.online ?? 0;
    } catch {
        /* keep the last value */
    }
}
// they just wrote or are typing: online, no need to wait for the next refresh
function sawOther(id: number | undefined) {
    if (!isGame && id && id === props.chat.other?.id) other.value = { online: true, lastSeen: new Date().toISOString() };
}

// ---------- live ----------
const typers = ref<Record<number, { name: string; until: number }>>({});
const typingText = computed(() => {
    const names = Object.values(typers.value)
        .filter((t) => t.until > now.value)
        .map((t) => t.name);
    if (!names.length) return '';
    if (names.length === 1) return trans('messages.typing_one', { name: names[0] });
    if (names.length === 2) return trans('messages.typing_two', { a: names[0], b: names[1] });
    return trans('messages.typing_many');
});
let subscribed = false;
const channelName = `conversation.${cid}`;
function subscribe() {
    if (subscribed || !window.Echo) return;
    subscribed = true;
    window.Echo.private(channelName)
        .listen('.message', (e: { action: string; message: ChatMessage }) => receive([e.message]))
        .listen('.read', (e: { user_id: number; message_id: number }) => {
            if (e.user_id !== me.value) reads.value = { ...reads.value, [e.user_id]: Math.max(reads.value[e.user_id] ?? 0, e.message_id) };
            sawOther(e.user_id);
        })
        .listenForWhisper('typing', (e: { id: number; name: string }) => {
            if (e.id !== me.value) typers.value[e.id] = { name: e.name, until: Date.now() + 4000 };
            sawOther(e.id);
        });
}
let lastWhisper = 0;
function whisperTyping() {
    if (!window.Echo || !subscribed || Date.now() - lastWhisper < 2500) return;
    lastWhisper = Date.now();
    try {
        window.Echo.private(channelName).whisper('typing', { id: me.value, name: user.value?.name || trans('messages.player') });
    } catch {
        /* not subscribed yet */
    }
}
// fallback + catching up after a reconnect
let polling = false;
async function poll() {
    if (polling) return;
    polling = true;
    try {
        const r = await api<{ messages: ChatMessage[] }>('GET', route('messages.history', { conversation: cid, after: lastId() }));
        await receive(r.messages);
    } catch {
        /* offline: next tick */
    } finally {
        polling = false;
    }
}
watch(echoState, (s, old) => {
    subscribe();
    if (s === 'connected' && old && old !== 'connected') poll();
});

// ---------- composer ----------
const text = ref('');
const sending = ref(false);
const error = ref('');
const replying = ref<ChatMessage | null>(null);
const editing = ref<ChatMessage | null>(null);
const input = ref<HTMLTextAreaElement>();
const len = computed(() => text.value.trim().length);
const tooLong = computed(() => text.value.length > props.maxLength);

// put the emoji where the cursor is, then keep typing
function insertEmoji(e: string) {
    const el = input.value;
    const at = el?.selectionStart ?? text.value.length;
    const end = el?.selectionEnd ?? at;
    text.value = text.value.slice(0, at) + e + text.value.slice(end);
    nextTick(() => {
        if (!el) return;
        el.focus();
        el.selectionStart = el.selectionEnd = at + e.length;
        grow();
    });
}
function grow() {
    const el = input.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 150) + 'px';
}
function onInput() {
    grow();
    error.value = '';
    if (text.value.trim()) whisperTyping();
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        submit();
    } else if (e.key === 'Escape') {
        cancelMode();
    }
}
function cancelMode() {
    if (editing.value) text.value = '';
    editing.value = null;
    replying.value = null;
    nextTick(grow);
}
async function submit() {
    const body = text.value.trim();
    if (!body || tooLong.value || sending.value) return;
    sending.value = true;
    error.value = '';
    try {
        if (editing.value) {
            const r = await api<{ message: ChatMessage }>('PATCH', route('messages.update', editing.value.id), { body });
            upsert([r.message]);
            editing.value = null;
        } else {
            const r = await api<{ message: ChatMessage }>('POST', route('messages.send', cid), { body, reply_to: replying.value?.id ?? null });
            upsert([r.message]);
            replying.value = null;
            toBottom(true);
        }
        text.value = '';
        await nextTick();
        grow();
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        sending.value = false;
        input.value?.focus();
    }
}

// ---------- message actions ----------
const active = ref<number | null>(null);
const confirmDelete = ref(false);
const reported = ref<number[]>([]);
const canChange = (m: ChatMessage) => !archived.value && m.type === 'text' && !m.deleted && m.user?.id === me.value && now.value - new Date(m.created_at).getTime() < props.editMinutes * 60e3;
function toggle(m: ChatMessage) {
    if (m.deleted || m.type === 'system' || Date.now() - swipedAt < 400) return;
    active.value = active.value === m.id ? null : m.id;
    confirmDelete.value = false;
}
function reply(m: ChatMessage) {
    editing.value = null;
    replying.value = m;
    active.value = null;
    input.value?.focus();
}

// ---------- swipe right to reply (touch screens, like WhatsApp) ----------
// The bubble follows the finger (up to SWIPE_MAX px) with a reply arrow on its left; past SWIPE_AT it replies on release.
// The direction is decided on the first 10 px, so scrolling the list up / down never starts a swipe.
const SWIPE_AT = 60;
const SWIPE_MAX = 80;
const drag = ref<{ id: number; dx: number } | null>(null);
let sw: { m: ChatMessage; x: number; y: number; dir: '' | 'h'; armed: boolean } | null = null;
let swipedAt = 0;
const canSwipe = (m: ChatMessage) => !archived.value && !m.deleted && m.type !== 'system';
function swStart(e: TouchEvent, m: ChatMessage) {
    sw = null;
    if (e.touches.length !== 1 || !canSwipe(m)) return;
    sw = { m, x: e.touches[0].clientX, y: e.touches[0].clientY, dir: '', armed: false };
}
function swMove(e: TouchEvent) {
    if (!sw) return;
    const dx = e.touches[0].clientX - sw.x;
    const dy = e.touches[0].clientY - sw.y;
    if (!sw.dir) {
        if (Math.hypot(dx, dy) < 10) return;
        // only a clearly horizontal move to the right is a swipe; anything else is scrolling
        if (dx <= 0 || Math.abs(dx) <= Math.abs(dy) * 1.5) {
            sw = null;
            return;
        }
        sw.dir = 'h';
        sw.x += 10; // start from here, so the bubble doesn't jump by the 10 px
    }
    const d = Math.max(0, Math.min(SWIPE_MAX, dx - 10));
    drag.value = { id: sw.m.id, dx: d };
    // a light tick when it is far enough (once per crossing), like WhatsApp
    if (d >= SWIPE_AT !== sw.armed) {
        sw.armed = d >= SWIPE_AT;
        if (sw.armed) tgApp()?.HapticFeedback?.impactOccurred('light');
    }
}
function swEnd() {
    const s = sw;
    sw = null;
    drag.value = null; // springs back (CSS transition)
    if (!s?.dir) return;
    swipedAt = Date.now(); // no tap menu right after a swipe
    if (s.armed) reply(s.m); // reply() focuses the box; still inside the touch gesture, so iOS opens the keyboard
}
function edit(m: ChatMessage) {
    replying.value = null;
    editing.value = m;
    text.value = m.body ?? '';
    active.value = null;
    nextTick(() => {
        grow();
        input.value?.focus();
    });
}
async function remove(m: ChatMessage) {
    if (!confirmDelete.value) {
        confirmDelete.value = true;
        return;
    }
    try {
        const r = await api<{ message: ChatMessage }>('DELETE', route('messages.destroy', m.id));
        upsert([r.message]);
    } catch (e) {
        error.value = (e as Error).message;
    }
    active.value = null;
    confirmDelete.value = false;
}
async function report(m: ChatMessage) {
    try {
        await api('POST', route('reports.store'), { type: 'message', id: m.id, target_type: 'message', target_id: m.id, reason: 'chat message' });
        reported.value.push(m.id);
    } catch (e) {
        error.value = (e as Error).message;
    }
    active.value = null;
}
function jumpTo(id: number) {
    const el = document.getElementById(`m${id}`);
    if (!el) return;
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el.classList.add('flash');
    window.setTimeout(() => el.classList.remove('flash'), 1200);
}
const quoteText = (r: NonNullable<ChatMessage['reply']>) => (r.deleted ? trans('messages.deleted') : r.type === 'system' ? sysText(r.body, r.meta) : (r.body ?? ''));

// ---------- lifecycle ----------
let pollTimer: number | undefined;
let clockTimer: number | undefined;
let presenceTimer: number | undefined;
onMounted(async () => {
    subscribe();
    pollTimer = window.setInterval(() => {
        subscribe();
        if (!echoConnected() && document.visibilityState === 'visible') poll();
    }, 5000);
    clockTimer = window.setInterval(() => (now.value = Date.now()), 1000);
    presenceTimer = window.setInterval(refreshPresence, 60e3);
    document.addEventListener('visibilitychange', onVisible);
    await nextTick();
    const first = firstUnread && document.getElementById(`m${firstUnread}`);
    if (first && scroller.value) {
        scroller.value.scrollTop = Math.max(0, first.offsetTop - 80);
    } else await toBottom();
    markRead();
});
onBeforeUnmount(() => {
    clearInterval(pollTimer);
    clearInterval(clockTimer);
    clearInterval(presenceTimer);
    clearTimeout(readTimer);
    document.removeEventListener('visibilitychange', onVisible);
    if (subscribed) window.Echo?.leave(channelName);
});
</script>

<template>
    <PlayerLayout fullscreen>
        <Head :title="chatTitle(chat)" />
        <div class="chat">
            <header class="chead">
                <Link class="back" :href="route('messages.index')" :aria-label="$t('messages.back')"><Icon name="back" /></Link>
                <span v-if="isGame" class="cav game"><Icon :name="chatIcon(chat)" /></span>
                <Avatar v-else :id="chat.other?.id ?? null" :name="chat.other?.name" :src="chat.other?.avatar" size="md" />
                <div class="ht">
                    <b>{{ chatTitle(chat) }}</b>
                    <span v-if="chat.game" class="hs">
                        {{ $t(`messages.status.${chat.game.status}`) }} · {{ $t('messages.players', { n: String(chat.game.players), t: String(chat.game.total) }) }} ·
                        <!-- "N online" before the member count: on phones the end of this line is cut -->
                        <template v-if="onlineN"><span class="on">{{ $t('messages.online_count', { n: String(onlineN) }) }}</span> · </template>{{ $t('messages.members', { n: String(chat.members) }) }}
                    </span>
                    <span v-else-if="chat.tournament" class="hs"
                        ><template v-if="onlineN"><span class="on">{{ $t('messages.online_count', { n: String(onlineN) }) }}</span> · </template>{{ $t('messages.members', { n: String(chat.members) }) }}</span
                    >
                    <span v-else-if="chat.venue" class="hs">{{
                        chat.venue.staff ? $t('messages.place_chat_staff') : $t('messages.place_chat')
                    }}</span>
                    <span v-else-if="seenText" :class="['hs', { on: otherOnline }]"
                        ><i v-if="otherOnline" class="on-dot" aria-hidden="true" />{{ seenText }}</span
                    >
                    <span v-else class="hs">{{ $t('messages.direct') }}</span>
                </div>
                <Link v-if="chat.tournament" class="btn outline sm" :href="chat.tournament.url" :aria-label="$t('messages.open_bracket')"
                    ><Icon name="trophy" size="sm" /><span class="hide-sm">{{ $t('messages.open_bracket') }}</span></Link
                >
                <Link v-if="chat.venue && !chat.venue.staff" class="btn outline sm" :href="chat.venue.url" :aria-label="chat.venue.name"
                    ><Icon name="pin" size="sm" /><span class="hide-sm">{{ $t('messages.open_place') }}</span></Link
                >
                <Link v-if="chat.game?.url" class="btn outline sm" :href="chat.game.url" :aria-label="$t('messages.open_game')"
                    ><Icon name="users" size="sm" /><span class="hide-sm">{{ $t('messages.open_game') }}</span></Link
                >
                <Icon v-if="chat.muted" name="bellOff" size="sm" class="mutedi" :title="$t('messages.is_muted')" />
                <div class="cmenu">
                    <button type="button" class="btn outline sm icon-only" :aria-label="$t('messages.chat_menu')" :aria-expanded="menuOpen" data-test="chat-menu" @click="menuOpen = !menuOpen">
                        <Icon name="more" size="sm" />
                    </button>
                    <div v-if="menuOpen" class="cmdrop" role="menu">
                        <button type="button" role="menuitem" data-test="chat-mute" @click="setMute(!chat.muted)">
                            <Icon :name="chat.muted ? 'bell' : 'bellOff'" size="sm" />{{ chat.muted ? $t('messages.unmute') : $t('messages.mute') }}
                        </button>
                        <button v-if="chat.canLeave" type="button" role="menuitem" class="danger" data-test="chat-leave" @click="leaveChat">
                            <Icon name="exit" size="sm" />{{ $t('messages.leave') }}
                        </button>
                        <button v-else type="button" role="menuitem" class="danger" data-test="chat-remove" @click="removeChat">
                            <Icon name="trash" size="sm" />{{ $t('messages.remove') }}
                        </button>
                    </div>
                </div>
            </header>
            <div v-if="menuOpen" class="cmscrim" @click="menuOpen = false" />

            <div v-if="bar" class="gbar">
                <Link :href="bar.url ?? '#'" class="gb-main">
                    <Icon name="calendar" size="sm" />
                    <span class="gb-t"
                        ><b>{{ bar.when }} · {{ bar.where }}</b><span class="gb-dot"> · </span><span :class="['gb-s', bar.state]">{{ $t(`messages.bar.${bar.state}`) }}</span></span
                    >
                </Link>
                <Link v-if="bar.canBook" :href="bar.url + '?book=1'" class="btn sm gb-book">{{ $t('messages.bar.book') }}<Icon name="arrow" size="sm" /></Link>
            </div>

            <div v-if="archived" class="banner">
                <Icon name="clock" size="sm" />
                <span>{{ chat.tournament ? $t('messages.tournament_over') : $t('messages.archived') }}</span>
                <Link v-if="chat.game?.url" class="btn sm" :href="chat.game.url">{{ $t('messages.play_again') }}</Link>
            </div>

            <div ref="scroller" class="cbody" @scroll.passive="onScroll" @click.self="active = null">
                <button v-if="more" type="button" class="older" :disabled="loadingOlder" @click="loadOlder">{{ $t('messages.older') }}</button>
                <p v-if="!msgs.length" class="none">{{ $t('messages.no_messages') }}</p>
                <template v-for="g in groups" :key="g.key">
                    <div class="dsep"><span>{{ g.label }}</span></div>
                    <template v-for="r in g.rows" :key="r.m.id">
                        <div v-if="r.m.id === firstUnread" class="newline"><span>{{ $t('messages.unread') }}</span></div>
                        <div v-if="r.m.type === 'system'" :id="`m${r.m.id}`" class="sys">
                            <span>{{ sysText(r.m.body, r.m.meta) }}</span>
                        </div>
                        <div v-else :id="`m${r.m.id}`" :class="['mrow', { mine: r.mine, head: r.head, tail: r.tail, host: isHost(r.m) }]">
                            <button
                                v-if="!r.mine && isGame"
                                type="button"
                                class="mav"
                                tabindex="-1"
                                :aria-label="userName(r.m.user)"
                                @click="openPerson(r.m.user)"
                            >
                                <Avatar v-if="r.tail" :id="r.m.user?.id ?? null" :name="userName(r.m.user)" :src="r.m.user?.avatar" size="sm" />
                            </button>
                            <div class="mcol">
                                <button
                                    v-if="!r.mine && isGame && r.head"
                                    type="button"
                                    class="mname"
                                    data-test="chat-person"
                                    @click="openPerson(r.m.user)"
                                    >{{ userName(r.m.user) }}<b v-if="isHost(r.m)" class="htag">{{ chat.venue ? chat.venue.name : $t('messages.organiser') }}</b></button
                                >
                                <div
                                    :class="['bubble', { deleted: r.m.deleted, on: active === r.m.id, drag: drag?.id === r.m.id }]"
                                    :style="drag?.id === r.m.id ? { transform: `translateX(${drag.dx}px)` } : undefined"
                                    role="button"
                                    tabindex="0"
                                    :aria-label="$t('messages.actions')"
                                    @click="toggle(r.m)"
                                    @keydown.enter.prevent="toggle(r.m)"
                                    @touchstart.passive="swStart($event, r.m)"
                                    @touchmove.passive="swMove"
                                    @touchend="swEnd"
                                    @touchcancel="swEnd"
                                >
                                    <svg v-if="drag?.id === r.m.id" class="ico swipe-ico" :style="{ opacity: Math.min(1, drag.dx / SWIPE_AT), transform: `translateY(-50%) scale(${drag.dx >= SWIPE_AT ? 1 : 0.7})` }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5" /><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11" /></svg>
                                    <button v-if="r.m.reply && !r.m.deleted" type="button" class="quote" @click.stop="jumpTo(r.m.reply.id)">
                                        <b>{{ r.m.reply.user?.id === me ? $t('messages.you') : userName(r.m.reply.user) }}</b>
                                        <span>{{ quoteText(r.m.reply) }}</span>
                                    </button>
                                    <span v-if="r.m.deleted" class="txt"><Icon name="trash" size="sm" />{{ $t('messages.deleted') }}</span>
                                    <span v-else class="txt">{{ r.m.body }}</span>
                                    <span class="meta">
                                        <template v-if="r.m.edited && !r.m.deleted">{{ $t('messages.edited') }} · </template>{{ clock(r.m.created_at) }}
                                        <span
                                            v-if="r.mine && !r.m.deleted"
                                            :class="['tick', { seen: seenBy(r.m.id) > 0 }]"
                                            :title="seenBy(r.m.id) ? (isGame ? $t('messages.seen_by', { n: String(seenBy(r.m.id)) }) : $t('messages.seen')) : $t('messages.sent')"
                                            >{{ seenBy(r.m.id) ? '✓✓' : '✓' }}</span
                                        >
                                    </span>
                                </div>
                                <div v-if="active === r.m.id" class="acts">
                                    <button v-if="!archived" type="button" @click="reply(r.m)"><Icon name="arrow" size="sm" />{{ $t('messages.reply') }}</button>
                                    <template v-if="canChange(r.m)">
                                        <button type="button" @click="edit(r.m)"><Icon name="edit" size="sm" />{{ $t('common.edit') }}</button>
                                        <button type="button" class="danger" @click="remove(r.m)"><Icon name="trash" size="sm" />{{ confirmDelete ? $t('messages.delete_sure') : $t('messages.delete') }}</button>
                                    </template>
                                    <button v-if="canReport && !r.mine && !reported.includes(r.m.id)" type="button" @click="report(r.m)"><Icon name="x" size="sm" />{{ $t('common.report') }}</button>
                                    <span v-if="reported.includes(r.m.id)" class="ok">{{ $t('messages.reported') }}</span>
                                </div>
                            </div>
                        </div>
                    </template>
                </template>
            </div>

            <div class="under">
                <button v-if="newBelow" type="button" class="jump" @click="toBottom(true)">{{ $t('messages.new_below') }} · {{ newBelow }}</button>
                <div class="typing" aria-live="polite">{{ typingText }}</div>
            </div>
            <div v-if="echoState !== 'connected' && echoState !== 'connecting' && echoState !== 'initialized'" class="offline">{{ $t('messages.offline') }}</div>

            <form v-if="!archived" class="composer" @submit.prevent="submit">
                <div v-if="replying || editing" class="mode">
                    <Icon :name="editing ? 'edit' : 'arrow'" size="sm" />
                    <div class="mt">
                        <b>{{ editing ? $t('messages.editing') : $t('messages.reply_to', { name: replying?.user?.id === me ? $t('messages.you') : userName(replying?.user) }) }}</b>
                        <span>{{ (editing ?? replying)?.body }}</span>
                    </div>
                    <button type="button" class="x" :aria-label="$t('common.cancel')" @click="cancelMode"><Icon name="x" size="sm" /></button>
                </div>
                <div class="box">
                    <EmojiPicker :label="$t('messages.emoji')" :football-label="$t('messages.emoji_football')" :reactions-label="$t('messages.emoji_reactions')" @pick="insertEmoji" />
                    <textarea
                        ref="input"
                        v-model="text"
                        rows="1"
                        :placeholder="$t('messages.placeholder')"
                        :aria-label="$t('messages.placeholder')"
                        :title="$t('messages.hint')"
                        @input="onInput"
                        @keydown="onKey"
                    />
                    <button type="submit" class="send" :disabled="!len || tooLong || sending" :aria-label="editing ? $t('common.save') : $t('common.send')">
                        <Icon :name="editing ? 'check' : 'send'" />
                    </button>
                </div>
                <div class="cfoot">
                    <span class="err" role="alert">{{ error }}</span>
                    <span v-if="showCount" :class="['count', { warn: text.length > maxLength - 100, over: tooLong }]">{{ text.length }}/{{ maxLength }}</span>
                </div>
            </form>
        </div>
        <!-- a member of a group chat: profile and friend buttons -->
        <Teleport to="body">
            <div v-if="person" class="pscrim" @click.self="person = null">
                <div class="psheet" role="dialog" aria-modal="true" :aria-label="person.name" data-test="chat-person-sheet">
                    <Avatar :id="person.id" :name="person.name" :src="person.avatar" size="lg" />
                    <h3>{{ person.name }}</h3>
                    <p v-if="person.username" class="faint">@{{ person.username }}</p>
                    <FriendActions
                        v-if="personRel !== 'blocked'"
                        :player="{
                            id: person.id,
                            name: person.name,
                            username: person.username,
                            avatar: person.avatar,
                            position: null,
                            district: null,
                            url: route('users.show', person.id),
                            relation: personRel,
                        }"
                        message
                    />
                    <Link class="btn ghost sm" :href="route('users.show', person.id)"
                        ><Icon name="user" size="sm" />{{ $t('messages.profile') }}</Link
                    >
                </div>
            </div>
        </Teleport>
    </PlayerLayout>
</template>

<style scoped>
.tick { margin-left: 4px; letter-spacing: -3px; color: var(--muted); font-size: 11px; }
.tick.seen { color: var(--accent-2); }
.chat { max-width: 820px; width: 100%; margin: 12px auto 0; display: flex; flex-direction: column; height: calc(100dvh - var(--app-top, 64px) - 24px); min-height: 420px; background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg); overflow: hidden; }
.chead { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-bottom: 1px solid var(--line); background: var(--bg-2); min-width: 0; }
.back { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 50%; color: var(--muted); flex: none; }
.back:hover { background: var(--surface-2); color: var(--ink); }
.cav { width: 40px; height: 40px; flex: none; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 14px; background: var(--surface-3); border: 1px solid var(--line-2); }
.cav.game { background: var(--free-soft); color: var(--accent-2); border-color: rgba(94, 240, 168, 0.3); }
.ht { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.ht b { font-size: 15.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hs { font-size: 12.5px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hs.on, .hs .on { color: var(--free); }
.on-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--free); margin-right: 5px; vertical-align: 1px; }
.gbar { display: flex; align-items: center; gap: 8px; padding: 6px 8px 6px 14px; border-bottom: 1px solid var(--line); background: var(--surface); min-width: 0; min-height: 44px; }
.gb-main { flex: 1; min-width: 0; display: flex; align-items: center; gap: 8px; color: var(--ink); font-size: 13.5px; font-weight: 700; }
.gb-main .ico { color: var(--muted); }
.gb-t { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.gb-t b { font-weight: 700; }
@media (max-width: 600px) {
    .gb-t { display: flex; flex-direction: column; line-height: 1.3; }
    .gb-t > * { overflow: hidden; text-overflow: ellipsis; }
    .gb-dot { display: none; }
    .gb-s { font-size: 12px; }
}
.gb-s.none, .gb-s.requested { color: var(--st-pending); }
.gb-s.booked { color: var(--st-confirmed); }
.gb-book { flex: none; }
.banner { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: var(--warn-soft); color: var(--warn); font-size: 13px; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
.banner span { flex: 1; min-width: 200px; }

.cbody { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 12px 14px 6px; display: flex; flex-direction: column; overscroll-behavior: contain; }
.older { align-self: center; margin: 4px 0 10px; border: 1px solid var(--line-2); background: var(--surface-2); color: var(--muted); border-radius: 999px; padding: 6px 14px; font-size: 12.5px; font-weight: 700; }
.none { text-align: center; color: var(--muted); margin: auto 0; }
.dsep { display: flex; align-items: center; gap: 10px; margin: 14px 0 6px; color: var(--muted); font-size: 12px; font-weight: 700; }
.dsep::before, .dsep::after { content: ''; flex: 1; height: 1px; background: var(--line); }
.newline { display: flex; align-items: center; gap: 10px; margin: 8px 0; color: var(--accent); font-size: 12px; font-weight: 800; }
.newline::before, .newline::after { content: ''; flex: 1; height: 1px; background: rgba(200, 255, 61, 0.35); }
.sys { text-align: center; margin: 8px 0; }
.sys span { display: inline-block; max-width: 92%; font-size: 12.5px; color: var(--muted); background: rgba(255, 255, 255, 0.03); border: 1px dashed var(--line-2); border-radius: 12px; padding: 5px 12px; overflow-wrap: anywhere; }

.mrow { display: flex; gap: 8px; align-items: flex-end; margin-top: 2px; min-width: 0; }
.mrow.head { margin-top: 10px; }
.mrow.mine { justify-content: flex-end; }
.mav { width: 30px; height: 30px; flex: none; display: grid; padding: 0; border: 0; background: none; cursor: pointer; }
.mname { border: 0; background: none; padding: 0; cursor: pointer; text-align: left; font-family: inherit; }
.cmenu { position: relative; flex: none; }
.cmdrop { position: absolute; right: 0; top: calc(100% + 6px); z-index: 40; min-width: 210px; background: var(--surface); border: 1px solid var(--line-2); border-radius: 14px; padding: 6px; box-shadow: 0 18px 40px -16px rgba(0, 0, 0, 0.9); display: grid; }
.cmdrop button { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 0; background: none; border-radius: 10px; color: var(--ink); font: 600 14px var(--body); text-align: left; cursor: pointer; }
.cmdrop button:hover { background: var(--surface-2); }
.cmdrop .danger { color: var(--hot); }
.cmscrim { position: fixed; inset: 0; z-index: 30; }
.mutedi { color: var(--muted); flex: none; }
.pscrim { position: fixed; inset: 0; z-index: 300; background: rgba(4, 8, 6, 0.55); display: flex; align-items: flex-end; justify-content: center; }
.psheet { width: 100%; max-width: 420px; background: var(--surface); border: 1px solid var(--line-2); border-radius: 22px 22px 0 0; padding: 20px 18px calc(18px + env(safe-area-inset-bottom)); display: flex; flex-direction: column; align-items: center; gap: 10px; text-align: center; }
.psheet h3 { margin: 0; font-size: 18px; }
.psheet .faint { margin: -6px 0 0; font-size: 13px; }
.psheet .facts-row { justify-content: center; }
@media (min-width: 640px) { .cmenu { position: relative; flex: none; }
.cmdrop { position: absolute; right: 0; top: calc(100% + 6px); z-index: 40; min-width: 210px; background: var(--surface); border: 1px solid var(--line-2); border-radius: 14px; padding: 6px; box-shadow: 0 18px 40px -16px rgba(0, 0, 0, 0.9); display: grid; }
.cmdrop button { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 0; background: none; border-radius: 10px; color: var(--ink); font: 600 14px var(--body); text-align: left; cursor: pointer; }
.cmdrop button:hover { background: var(--surface-2); }
.cmdrop .danger { color: var(--hot); }
.cmscrim { position: fixed; inset: 0; z-index: 30; }
.mutedi { color: var(--muted); flex: none; }
.pscrim { align-items: center; } .psheet { border-radius: 22px; } }
.mcol { display: flex; flex-direction: column; max-width: min(78%, 560px); min-width: 0; }
.mine .mcol { align-items: flex-end; }
.mname { font-size: 12px; font-weight: 700; color: var(--accent-2); margin: 0 0 3px 10px; }
.bubble { position: relative; background: var(--surface-3); border-radius: 16px; padding: 7px 12px 6px; cursor: pointer; min-width: 0; max-width: 100%; transition: box-shadow 0.2s, transform 0.25s cubic-bezier(0.2, 0.9, 0.3, 1.2); touch-action: pan-y; -webkit-tap-highlight-color: transparent; }
/* swipe to reply: no transition while the finger drags it; the arrow sits left of the bubble */
.bubble.drag { transition: box-shadow 0.2s; }
.swipe-ico { position: absolute; left: -32px; top: 50%; width: 22px; height: 22px; padding: 3px; border-radius: 50%; background: var(--surface-2); color: var(--accent-2); transition: transform 0.15s; pointer-events: none; }
.mrow:not(.tail) .bubble { border-bottom-left-radius: 6px; }
.mrow:not(.head) .bubble { border-top-left-radius: 6px; }
/* tournament: the organiser's messages */
.host .bubble { background: rgba(255, 159, 67, 0.16); border: 1px solid rgba(255, 159, 67, 0.45); font-weight: 600; }
.htag { margin-left: 6px; padding: 1px 7px; border-radius: 99px; background: #ff9f43; color: #1d0f00; font-size: 10.5px; font-weight: 800; }
.mine .bubble { background: rgba(200, 255, 61, 0.14); border: 1px solid rgba(200, 255, 61, 0.22); }
.mine.mrow:not(.tail) .bubble { border-bottom-left-radius: 16px; border-bottom-right-radius: 6px; }
.mine.mrow:not(.head) .bubble { border-top-left-radius: 16px; border-top-right-radius: 6px; }
.bubble.on { box-shadow: 0 0 0 2px var(--accent); }
.bubble.deleted { background: transparent; border: 1px dashed var(--line-2); color: var(--faint); font-style: italic; }
.txt { white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-word; font-size: 14.5px; display: inline; }
.txt .ico { vertical-align: -2px; margin-right: 5px; }
.meta { float: right; margin: 6px 0 -2px 10px; font-size: 10.5px; color: var(--faint); line-height: 1.6; white-space: nowrap; }
.quote { display: flex; flex-direction: column; text-align: left; width: 100%; border: 0; border-left: 3px solid var(--accent-2); background: rgba(0, 0, 0, 0.22); border-radius: 8px; padding: 4px 8px; margin: 2px 0 5px; font-size: 12.5px; min-width: 0; }
.quote b { color: var(--accent-2); font-size: 12px; }
.quote span { color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%; }
.acts { display: flex; flex-wrap: wrap; gap: 6px; margin: 6px 0 2px; }
.acts button { display: inline-flex; align-items: center; gap: 5px; border: 1px solid var(--line-2); background: var(--surface-2); border-radius: 999px; padding: 4px 10px; font-size: 12.5px; font-weight: 700; color: var(--ink); }
.acts button:hover { background: var(--surface-3); }
.acts .danger { color: var(--hot); }
.acts .ok { font-size: 12.5px; color: var(--free); }
:global(.mrow.flash .bubble) { box-shadow: 0 0 0 2px var(--blue); }

.under { position: relative; min-height: 20px; padding: 0 16px; }
.typing { font-size: 12px; color: var(--muted); font-style: italic; height: 20px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.jump { position: absolute; left: 50%; transform: translateX(-50%); bottom: 26px; border: 0; border-radius: 999px; background: var(--accent); color: var(--on-accent); font-weight: 800; font-size: 12.5px; padding: 6px 14px; box-shadow: var(--shadow); z-index: 3; white-space: nowrap; }
.offline { font-size: 12px; color: var(--warn); padding: 0 16px 4px; }

.composer { border-top: 1px solid var(--line); padding: 10px 12px calc(10px + var(--sab)); background: var(--bg-2); }
.mode { display: flex; align-items: center; gap: 8px; padding: 4px 4px 8px; color: var(--accent-2); min-width: 0; }
.mt { flex: 1; min-width: 0; display: flex; flex-direction: column; font-size: 12.5px; }
.mt b { color: var(--accent-2); }
.mt span { color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.x { border: 0; background: var(--surface-2); color: var(--muted); width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; flex: none; }
.box { display: flex; gap: 8px; align-items: flex-end; }
.box textarea { flex: 1; min-width: 0; resize: none; background: var(--surface); border: 1px solid var(--line-2); border-radius: 20px; padding: 9px 14px; line-height: 1.4; max-height: 150px; outline: none; }
.box textarea:focus { border-color: rgba(200, 255, 61, 0.5); }
.send { width: 42px; height: 42px; flex: none; border-radius: 50%; border: 0; background: var(--grad); color: var(--on-accent); display: grid; place-items: center; }
.send:disabled { background: var(--surface-2); color: var(--faint); cursor: not-allowed; }
.cfoot { display: flex; justify-content: space-between; gap: 10px; padding: 3px 6px 0; font-size: 11.5px; }
.cfoot:has(.err:empty):not(:has(.count)) { display: none; }
.err { color: var(--hot); }
.count { color: var(--faint); flex: none; }
.count.warn { color: var(--warn); }
.count.over { color: var(--hot); font-weight: 800; }

/* phones / tablets: the layout is fullscreen (no header, no tab bar), the chat fills it edge to edge */
@media (max-width: 900px) {
    .chat { flex: 1 1 auto; height: auto; min-height: 0; margin: 0 -20px; width: auto; max-width: none; border-radius: 0; border: 0; }
    .chead { padding-top: calc(10px + env(safe-area-inset-top)); }
}
@media (max-width: 600px) {
    .chat { margin: 0 -16px; }
    .hide-sm { display: none; }
    .chead { padding: calc(8px + env(safe-area-inset-top)) 10px 8px; gap: 8px; }
    .cbody { padding: 10px 10px 6px; }
    .mcol { max-width: 84%; }
}
</style>
