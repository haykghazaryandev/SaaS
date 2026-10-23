<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { type ChatItem, chatTitle, initials, shortWhen, sysText, userName } from '@/composables/useChat';
import { useShared } from '@/composables/useShared';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, onMounted } from 'vue';

// Messages: direct and game chats with the last message and unread counts; closed (archived) chats below.
// Live: private-user.{id} ".chat.updated" reloads the list.
defineProps<{ chats: ChatItem[]; archived: ChatItem[] }>();
const { user } = useShared();

function preview(c: ChatItem): string {
    const m = c.last;
    if (!m) return '';
    if (m.type === 'system') return sysText(m.body, m.meta);
    const who = m.user?.id === user.value?.id ? trans('messages.you') : c.type === 'game' ? userName(m.user) : '';
    return (who ? who + ': ' : '') + (m.deleted ? trans('messages.deleted') : (m.body ?? ''));
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
onMounted(() => {
    listen();
    // Echo starts right after login; try again shortly if it was not ready yet
    retry = window.setInterval(() => (channel ? clearInterval(retry) : listen()), 1000);
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    clearInterval(retry);
    if (channel) window.Echo?.private(channel).stopListening('.chat.updated', refresh);
});
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('messages.title')" />
        <div class="msgs">
            <header class="mhead">
                <span class="kicker">{{ $t('messages.sub') }}</span>
                <h1>{{ $t('messages.title') }}</h1>
            </header>

            <section v-if="chats.length" class="card clist">
                <Link v-for="c in chats" :key="c.id" :href="route('messages.show', c.id)" :class="['citem', { unread: c.unread }]">
                    <span :class="['cav', c.type]">
                        <Icon v-if="c.type === 'game'" name="ball" />
                        <template v-else>{{ initials(c.other?.name) }}</template>
                    </span>
                    <span class="cmain">
                        <span class="crow">
                            <b class="ctitle">{{ chatTitle(c) }}</b>
                            <span class="ctime">{{ shortWhen(c.last?.created_at ?? c.at) }}</span>
                        </span>
                        <span v-if="c.game" class="cgame">
                            <span :class="['gst', c.game.status]">{{ $t(`messages.status.${c.game.status}`) }}</span>
                            <span>{{ $t('messages.players', { n: String(c.game.players), t: String(c.game.total) }) }}</span>
                        </span>
                        <span class="crow">
                            <span :class="['cprev', { sys: c.last?.type === 'system' }]">{{ preview(c) }}</span>
                            <span v-if="c.unread" class="badge" :aria-label="String(c.unread)">{{ c.unread > 99 ? '99+' : c.unread }}</span>
                        </span>
                    </span>
                </Link>
            </section>
            <div v-else class="cempty card">
                <Icon name="chat" size="lg" />
                <h3>{{ $t('messages.empty') }}</h3>
                <p>{{ $t('messages.emptyHint') }}</p>
                <Link v-if="route().has('games.index')" class="btn sm" :href="route('games.index')"><Icon name="search" size="sm" />{{ $t('messages.findGame') }}</Link>
            </div>

            <template v-if="archived.length">
                <h2 class="sec">{{ $t('messages.archivedTitle') }}</h2>
                <section class="card clist off">
                    <Link v-for="c in archived" :key="c.id" :href="route('messages.show', c.id)" class="citem">
                        <span :class="['cav', c.type]">
                            <Icon v-if="c.type === 'game'" name="ball" />
                            <template v-else>{{ initials(c.other?.name) }}</template>
                        </span>
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
    </PlayerLayout>
</template>

<style scoped>
.msgs { max-width: 760px; margin: 0 auto; padding: 22px 0 40px; }
.mhead { margin-bottom: 16px; }
.mhead h1 { font-family: var(--display); font-size: 28px; margin: 4px 0 0; letter-spacing: -0.02em; }
.sec { font-size: 14px; color: var(--muted); font-weight: 700; margin: 26px 4px 10px; }
.clist { padding: 6px; display: flex; flex-direction: column; }
.citem { display: flex; gap: 12px; align-items: center; padding: 12px; border-radius: 14px; min-width: 0; }
.citem:hover { background: var(--surface-2); }
.citem + .citem { border-top: 1px solid var(--line); }
.cav { width: 46px; height: 46px; flex: none; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 15px; background: var(--surface-3); border: 1px solid var(--line-2); }
.cav.game { background: var(--free-soft); color: var(--accent-2); border-color: rgba(94, 240, 168, 0.3); }
.cmain { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.crow { display: flex; align-items: center; gap: 10px; min-width: 0; }
.ctitle { flex: 1; min-width: 0; font-size: 15px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ctime { font-size: 12px; color: var(--faint); flex: none; }
.cprev { flex: 1; min-width: 0; color: var(--muted); font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cprev.sys { font-style: italic; color: var(--faint); }
.unread .ctitle { color: var(--ink); }
.unread .cprev { color: var(--ink); font-weight: 600; }
.unread .ctime { color: var(--accent); }
.badge { flex: none; min-width: 22px; height: 22px; padding: 0 7px; border-radius: 999px; background: var(--accent); color: var(--on-accent); font-size: 12px; font-weight: 800; display: grid; place-items: center; }
.cgame { display: flex; gap: 8px; align-items: center; font-size: 12px; color: var(--muted); }
.gst { padding: 1px 8px; border-radius: 999px; background: var(--surface-3); font-weight: 700; font-size: 11.5px; }
.gst.open { background: var(--free-soft); color: var(--free); }
.gst.full, .gst.booked { background: var(--blue-soft); color: var(--blue); }
.gst.cancelled { background: var(--hot-soft); color: var(--hot); }
.off { opacity: 0.72; }
.cempty { text-align: center; padding: 40px 20px; color: var(--muted); display: flex; flex-direction: column; align-items: center; gap: 6px; }
.cempty h3 { color: var(--ink); margin: 6px 0 0; }
.cempty p { margin: 0 0 12px; max-width: 380px; }
@media (max-width: 600px) {
    .msgs { padding-top: 16px; }
    .mhead h1 { font-size: 22px; }
    .citem { padding: 10px 8px; gap: 10px; }
}
</style>
