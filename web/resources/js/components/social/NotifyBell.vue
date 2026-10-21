<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import { useShared } from '@/composables/useShared';
import { markRead, refresh, social } from '@/composables/useSocial';
import type { Notice } from '@/types/social';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// The bell in the header: unread badge, a dropdown with the last 30 notices, and a toast for live ones.
const { locale } = useShared();
const page = usePage();
const open = ref(false);
const el = ref<HTMLElement>();
const unread = computed(() => social.counts.notifications);

function toggle() {
    open.value = !open.value;
    if (open.value) {
        social.toast = null;
        refresh();
    }
}
function go(n: Notice) {
    if (!n.read_at) markRead(n.id);
    open.value = false;
    if (n.link) router.visit(n.link);
}
// an icon for notices that are not about a person
const iconOf = (n: Notice) => (n.type.startsWith('booking_') ? 'ticket' : n.type.startsWith('game_') ? 'ball' : n.type.startsWith('friend_') ? 'users' : 'bell');
function goToast() {
    const link = social.toast?.link;
    social.toast = null;
    if (link) router.visit(link);
}

// "5 min ago" in the page language
function ago(iso: string): string {
    const s = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const rtf = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' });
    const a = Math.abs(s);
    if (a < 60) return rtf.format(Math.round(s), 'second');
    if (a < 3600) return rtf.format(Math.round(s / 60), 'minute');
    if (a < 86400) return rtf.format(Math.round(s / 3600), 'hour');
    return rtf.format(Math.round(s / 86400), 'day');
}

function onDoc(e: MouseEvent) {
    if (open.value && el.value && !el.value.contains(e.target as Node)) open.value = false;
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') open.value = false;
}
onMounted(() => {
    document.addEventListener('click', onDoc);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDoc);
    document.removeEventListener('keydown', onKey);
});
watch(() => page.url, () => (open.value = false));
</script>

<template>
    <div ref="el" class="bell">
        <button type="button" class="bell-btn" :aria-expanded="open" aria-haspopup="true" :aria-label="$t('friends.bell.open')" :title="$t('friends.bell.open')" @click="toggle">
            <Icon name="bell" />
            <span v-if="unread" class="badge-dot bdg">{{ unread > 99 ? '99+' : unread }}</span>
        </button>
        <div v-if="open" class="bdrop" role="dialog" :aria-label="$t('friends.bell.title')">
            <div class="bhead">
                <b>{{ $t('friends.bell.title') }}</b>
                <button v-if="unread" type="button" class="linkbtn" @click="markRead()">{{ $t('friends.bell.readAll') }}</button>
            </div>
            <div class="blist">
                <button v-for="n in social.items" :key="n.id" type="button" :class="['bitem', { new: !n.read_at }]" @click="go(n)">
                    <Avatar v-if="n.name" :name="n.name" size="sm" />
                    <span v-else class="bico" aria-hidden="true"><Icon :name="iconOf(n)" size="sm" /></span>
                    <span class="btxt">
                        <span>{{ n.text }}</span>
                        <small>{{ ago(n.created_at) }}</small>
                    </span>
                    <span class="dot" aria-hidden="true" />
                </button>
                <p v-if="social.loaded && !social.items.length" class="bempty">{{ $t('friends.bell.empty') }}</p>
                <p v-if="!social.loaded" class="bempty">…</p>
            </div>
        </div>
    </div>
    <Teleport to="body">
        <button v-if="social.toast && !open" type="button" class="ntoast" role="status" @click="goToast"><Icon name="bell" size="sm" /><span>{{ social.toast.text }}</span></button>
    </Teleport>
</template>

<style scoped>
.bell { position: relative; }
.bell-btn { position: relative; width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; background: var(--surface); border: 1px solid var(--line-2); color: var(--ink); padding: 0; }
.bell-btn:hover, .bell-btn[aria-expanded="true"] { border-color: var(--accent); }
.bdg { position: absolute; top: -5px; right: -6px; box-shadow: 0 0 0 2px var(--bg); }
.bdrop { position: absolute; right: -50px; top: calc(100% + 8px); width: 360px; max-width: calc(100vw - 24px); background: var(--surface); border: 1px solid var(--line-2); border-radius: 16px; box-shadow: var(--shadow); z-index: 60; overflow: hidden; }
.bhead { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; border-bottom: 1px solid var(--line); }
.linkbtn { background: none; border: 0; color: var(--accent); font-weight: 700; font-size: 12.5px; padding: 0; }
.blist { max-height: min(440px, 70vh); overflow-y: auto; padding: 4px; }
.bitem { display: flex; align-items: flex-start; gap: 10px; width: 100%; text-align: left; background: none; border: 0; padding: 10px; border-radius: 10px; color: var(--muted); font-size: 13.5px; }
.bitem:hover { background: var(--surface-2); }
.bitem.new { color: var(--ink); }
.bitem .dot { width: 8px; height: 8px; border-radius: 50%; margin: 6px 0 0 auto; flex: none; background: transparent; }
.bico { width: 30px; height: 30px; border-radius: 50%; flex: none; display: grid; place-items: center; background: var(--surface-3); color: var(--muted); }
.bitem.new .bico { color: var(--accent); }
.bitem.new .dot { background: var(--accent); }
.btxt { flex: 1; display: flex; flex-direction: column; gap: 2px; min-width: 0; overflow-wrap: anywhere; }
.btxt small { color: var(--faint); font-size: 11.5px; }
.bempty { margin: 0; padding: 22px 14px; text-align: center; color: var(--muted); font-size: 13.5px; }
.ntoast { position: fixed; right: 16px; top: 76px; z-index: 95; max-width: min(380px, calc(100vw - 32px)); display: flex; gap: 10px; align-items: flex-start; text-align: left; background: var(--surface-2); color: var(--ink); border: 1px solid rgba(200, 255, 61, 0.35); border-radius: 14px; padding: 12px 14px; box-shadow: var(--shadow); font-weight: 600; font-size: 13.5px; animation: nt-in 0.25s ease-out; }
.ntoast .ico { color: var(--accent); margin-top: 2px; }
@keyframes nt-in { from { transform: translateY(-8px); opacity: 0; } }
@media (max-width: 600px) {
  .bell { position: static; }
  .bdrop { position: fixed; left: 12px; right: 12px; top: 64px; width: auto; max-width: none; }
  .ntoast { left: 16px; right: 16px; top: 72px; max-width: none; }
}
</style>
