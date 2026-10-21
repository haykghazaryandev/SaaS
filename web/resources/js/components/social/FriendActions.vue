<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import ConfirmSheet from '@/components/social/ConfirmSheet.vue';
import type { PlayerCard } from '@/types/social';
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Friend buttons for a player, by relation:
 *   none → Add friend · received → Accept / Decline · sent → Requested (cancel) · friends → Friends ✓ (menu: Remove)
 * `message` adds a "Message" button for friends (opens the direct chat).
 */
const props = defineProps<{ player: PlayerCard; message?: boolean }>();
const busy = ref(false);
const menu = ref(false);
const confirming = ref(false);
const el = ref<HTMLElement>();
const canChat = (() => {
    try {
        return route().has('messages.direct');
    } catch {
        return false;
    }
})();

const opts = { preserveScroll: true, preserveState: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) };
const add = () => router.post(route('friends.request', props.player.id), {}, opts);
const accept = () => router.post(route('friends.accept', props.player.id), {}, opts);
const remove = () => router.delete(route('friends.remove', props.player.id), { ...opts, onSuccess: () => (confirming.value = false) });

function onDoc(e: MouseEvent) {
    if (menu.value && el.value && !el.value.contains(e.target as Node)) menu.value = false;
}
onMounted(() => document.addEventListener('click', onDoc));
onBeforeUnmount(() => document.removeEventListener('click', onDoc));
</script>

<template>
    <div ref="el" class="facts-row">
        <template v-if="player.relation === 'none'">
            <button type="button" class="btn sm" :disabled="busy" @click="add"><Icon name="plus" size="sm" />{{ $t('friends.add') }}</button>
        </template>
        <template v-else-if="player.relation === 'received'">
            <button type="button" class="btn sm" :disabled="busy" @click="accept"><Icon name="check" size="sm" />{{ $t('friends.accept') }}</button>
            <button type="button" class="btn outline sm" :disabled="busy" @click="remove">{{ $t('friends.decline') }}</button>
        </template>
        <template v-else-if="player.relation === 'sent'">
            <span class="btn outline sm is-state"><Icon name="clock" size="sm" />{{ $t('friends.requested') }}</span>
            <button type="button" class="btn outline sm icon-only" :disabled="busy" :title="$t('friends.cancel')" :aria-label="$t('friends.cancel')" @click="remove"><Icon name="x" size="sm" /></button>
        </template>
        <template v-else-if="player.relation === 'friends'">
            <Link v-if="message && canChat" :href="route('messages.direct', player.id)" method="post" as="button" class="btn outline sm"><Icon name="chat" size="sm" />{{ $t('friends.message') }}</Link>
            <div class="fmenu">
                <button type="button" class="btn outline sm is-friend" :aria-expanded="menu" aria-haspopup="menu" @click="menu = !menu"><Icon name="check" size="sm" />{{ $t('friends.isFriend') }}</button>
                <div v-if="menu" class="fdrop" role="menu">
                    <button type="button" role="menuitem" class="danger" @click="(menu = false), (confirming = true)"><Icon name="x" size="sm" />{{ $t('friends.remove') }}</button>
                </div>
            </div>
        </template>
        <ConfirmSheet
            v-if="confirming"
            :title="trans('friends.profile.removeConfirm', { name: player.name })"
            :text="trans('friends.profile.removeText')"
            :yes="trans('friends.remove')"
            :busy="busy"
            @close="confirming = false"
            @confirm="remove"
        />
    </div>
</template>

<style scoped>
.facts-row { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.is-state { cursor: default; color: var(--muted); }
.is-state:hover { transform: none; }
.is-friend { color: var(--st-confirmed); }
.icon-only { padding: 7px 9px; }
.fmenu { position: relative; }
.fdrop { position: absolute; right: 0; top: calc(100% + 6px); min-width: 210px; background: var(--surface); border: 1px solid var(--line-2); border-radius: 14px; box-shadow: var(--shadow); padding: 5px; z-index: 30; }
.fdrop button { display: flex; align-items: center; gap: 9px; width: 100%; padding: 9px 11px; border-radius: 9px; background: none; border: 0; font-weight: 700; font-size: 13.5px; color: var(--ink); text-align: left; white-space: nowrap; }
.fdrop button:hover { background: var(--surface-2); }
.fdrop .danger:hover { color: var(--hot); }
</style>
