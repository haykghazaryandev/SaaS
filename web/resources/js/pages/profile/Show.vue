<script setup lang="ts">
import { SPORT_ICON } from '@/composables/usePlayer';
import Icon from '@/components/Icon.vue';
import ConfirmSheet from '@/components/social/ConfirmSheet.vue';
import FriendActions from '@/components/social/FriendActions.vue';
import Avatar from '@/components/Avatar.vue';
import ReportSheet from '@/components/social/ReportSheet.vue';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { PlayerCard } from '@/types/social';
import { Head, Link, router } from '@inertiajs/vue3';
import { trans } from '@/lib/i18n';
import { onBeforeUnmount, onMounted, ref } from 'vue';

// A player's profile: who they are, games / friends, friend buttons, Message (if allowed), Block / Report.
const props = defineProps<{
    player: PlayerCard & { bio?: string | null; games?: number; friends?: number; ratings?: Record<string, { rating: number; intl_id: string | null }> };
    hidden: boolean;
    blockedByMe: boolean;
    canMessage: boolean;
}>();

const canChat = (() => {
    try {
        return route().has('messages.direct');
    } catch {
        return false;
    }
})();
const menu = ref(false);
const menuEl = ref<HTMLElement>();
const blocking = ref(false);
const reporting = ref(false);
const busy = ref(false);
const opts = { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) };
const block = () => router.post(route('users.block', props.player.id), {}, { ...opts, onSuccess: () => (blocking.value = false) });
const unblock = () => router.delete(route('users.unblock', props.player.id), opts);

function onDoc(e: MouseEvent) {
    if (menu.value && menuEl.value && !menuEl.value.contains(e.target as Node)) menu.value = false;
}
onMounted(() => document.addEventListener('click', onDoc));
onBeforeUnmount(() => document.removeEventListener('click', onDoc));
</script>

<template>
    <PlayerLayout>
        <Head :title="hidden && !blockedByMe ? $t('common.friends') : player.name" />

        <!-- blocked (either way): no details -->
        <section v-if="hidden" class="card prof hiddenp">
            <Avatar :id="player.id" :name="blockedByMe ? player.name : null" size="xl" />
            <div style="min-width: 0">
                <h1 v-if="blockedByMe">{{ player.name }}</h1>
                <p class="muted" style="margin: 6px 0 0">{{ blockedByMe ? $t('friends.profile.blocked_by_me') : $t('friends.profile.unavailable') }}</p>
                <button v-if="blockedByMe" type="button" class="btn outline sm" style="margin-top: 14px" :disabled="busy" @click="unblock">{{ $t('common.unblock') }}</button>
            </div>
        </section>

        <template v-else>
            <section class="card prof">
                <Avatar :id="player.id" :name="player.name" :src="player.avatar" size="xl" class="bigav" />
                <div class="who">
                    <span class="kicker">{{ player.relation === 'self' ? $t('friends.you') : $t('friends.kicker') }}</span>
                    <h1>{{ player.name }}</h1>
                    <div v-if="player.username" class="muted uname">@{{ player.username }}</div>
                    <div class="row wrapflex" style="gap: 6px; margin-top: 10px">
                        <span v-if="player.district" class="pill"><Icon name="pin" size="sm" />{{ $t('common.districts.' + player.district) }}</span>
                        <!-- a rating per sport: 🏓 1450 is the ping-pong one, football has its own -->
                        <span v-for="(r, sp) in player.ratings ?? {}" :key="sp" class="pill lime" :title="$t('club.rating_of', { sport: $t(`common.sports.${sp}`) })"
                            >{{ SPORT_ICON[sp] ?? '' }} {{ $t('club.rating') }} {{ r.rating }}</span
                        >
                    </div>
                </div>
                <div class="pstats">
                    <div><span>{{ $t('common.games') }}</span><b>{{ player.games ?? 0 }}</b></div>
                    <div><span>{{ $t('common.friends') }}</span><b>{{ player.friends ?? 0 }}</b></div>
                </div>
                <div class="acts">
                    <template v-if="player.relation === 'self'">
                        <Link class="btn outline sm" :href="route('me.profile')"><Icon name="edit" size="sm" />{{ $t('friends.profile.edit') }}</Link>
                    </template>
                    <template v-else>
                        <FriendActions :player="player" />
                        <Link v-if="canMessage && canChat" :href="route('messages.direct', player.id)" method="post" as="button" class="btn outline sm"><Icon name="chat" size="sm" />{{ $t('friends.message') }}</Link>
                        <div ref="menuEl" class="pmenu">
                            <button type="button" class="btn ghost sm more" :aria-expanded="menu" aria-haspopup="menu" :aria-label="$t('common.more')" :title="$t('common.more')" @click="menu = !menu">•••</button>
                            <div v-if="menu" class="pdrop" role="menu">
                                <button type="button" role="menuitem" @click="(menu = false), (reporting = true)"><Icon name="eye" size="sm" />{{ $t('common.report') }}</button>
                                <button type="button" role="menuitem" class="danger" @click="(menu = false), (blocking = true)"><Icon name="x" size="sm" />{{ $t('common.block') }}</button>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            <section class="card about">
                <h2>{{ $t('common.about') }}</h2>
                <p :class="{ muted: !player.bio }">{{ player.bio || $t('friends.profile.no_bio') }}</p>
            </section>
        </template>

        <ConfirmSheet
            v-if="blocking"
            :title="trans('friends.profile.block_confirm', { name: player.name })"
            :text="trans('friends.profile.block_text')"
            :yes="trans('common.block')"
            :busy="busy"
            @close="blocking = false"
            @confirm="block"
        />
        <ReportSheet v-if="reporting" target-type="user" :target-id="player.id" :name="player.name" @close="reporting = false" />
    </PlayerLayout>
</template>

<style scoped>
.prof { margin-top: 26px; position: relative; padding: 24px; display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 8px 20px; align-items: center; }
.prof { background: radial-gradient(360px 260px at calc(100% - 80px) -30px, rgba(200, 255, 61, 0.1), transparent 70%), var(--surface); }
.prof h1 { font-size: clamp(22px, 3vw, 30px); margin: 2px 0 0; overflow-wrap: anywhere; }
.uname { font-size: 14px; }
.who { min-width: 0; }
.pstats { display: flex; gap: 10px; position: relative; }
.pstats div { background: var(--bg-2); border: 1px solid var(--line); border-radius: 14px; padding: 10px 14px; min-width: 92px; }
.pstats b { display: block; font-family: var(--display); font-size: 19px; font-weight: 600; }
.pstats span { display: block; font-size: 12.5px; color: var(--muted); font-weight: 600; white-space: nowrap; }
.acts { grid-column: 2 / -1; display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-top: 10px; position: relative; }
.hiddenp { grid-template-columns: auto 1fr; }
.pmenu { position: relative; }
.about { margin-top: 14px; padding: 20px 24px; }
.about h2 { font-family: var(--body); font-size: 15px; font-weight: 800; letter-spacing: 0; margin: 0 0 6px; }
.about p { margin: 0; white-space: pre-line; overflow-wrap: anywhere; }
@media (max-width: 760px) {
  .prof { grid-template-columns: auto minmax(0, 1fr); padding: 18px; gap: 8px 14px; }
  .pstats { grid-column: 1 / -1; margin-top: 8px; }
  .pstats div { flex: 1; min-width: 0; }
  .acts { grid-column: 1 / -1; }
  .about { padding: 16px 18px; }
}
</style>
