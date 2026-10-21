<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import { sizeOf, whenText, whereText } from '@/composables/useGames';
import type { GameCard } from '@/types/games';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Avatars from './Avatars.vue';
import GameProgress from './GameProgress.vue';
import GameStatusPill from './GameStatusPill.vue';

// A game in a list. Headline: where + when (or a "Time TBD" chip); the format as a chip; "N spots left" big;
// players and an action slot (Join, Accept / Decline …). Invites get a top line "Aram invited you".
const props = defineProps<{ game: GameCard; invite?: boolean }>();
const when = computed(() => {
    const g = props.game;
    if (g.booking) return whenText({ date: g.booking.date, from: g.booking.start, to: g.booking.end });
    return g.window ? whenText(g.window) : null;
});
</script>

<template>
    <article :class="['card gcard', `st-${game.status}`, { inv: invite }]">
        <Link class="cover" :href="route('games.show', game.id)" :aria-label="`${game.format} · ${whereText(game)}`" />
        <div v-if="invite && game.invitedBy" class="invline">
            <Avatar :id="game.invitedBy.id" :name="game.invitedBy.name" :src="game.invitedBy.avatar" size="xs" />
            <span>{{ $t('games.card.invitedYou', { name: game.invitedBy.short }) }}</span>
        </div>
        <div class="ghd">
            <div class="where">
                <h3>{{ whereText(game) }}</h3>
                <div class="when">
                    <template v-if="when"><Icon name="calendar" size="sm" />{{ when }}</template>
                    <span v-else class="tbd"><Icon name="clock" size="sm" />{{ $t('games.card.tbd') }}</span>
                </div>
            </div>
            <div class="tags">
                <span class="fmt"><i :class="['field-dot', sizeOf(game.perSide)]" />{{ game.format }}</span>
                <GameStatusPill v-if="game.status !== 'open'" :status="game.status" />
            </div>
        </div>
        <p v-if="game.note" class="note">“{{ game.note }}”</p>
        <GameProgress :in-count="game.in" :total="game.total" :min-to-open="game.minToOpen" :status="game.status" />
        <div class="gfoot">
            <div class="who">
                <Avatars :people="game.avatars.slice(0, 4)" :more="game.in - Math.min(4, game.avatars.length)" />
                <span class="by">
                    <template v-if="game.mine">{{ $t('games.card.yours') }}</template>
                    <template v-else-if="game.me === 'accepted' || game.me === 'joined'">{{ $t('games.card.youIn') }}</template>
                    <template v-else>{{ $t('games.card.by', { name: game.creator.short }) }}</template>
                </span>
            </div>
            <div class="acts"><slot /></div>
        </div>
    </article>
</template>

<style scoped>
.gcard { position: relative; padding: 16px; display: flex; flex-direction: column; gap: 12px; transition: transform .18s, border-color .18s; min-width: 0; }
.gcard:hover { transform: translateY(-2px); border-color: var(--line-2); }
.cover { position: absolute; inset: 0; z-index: 0; border-radius: inherit; }
.gcard > *:not(.cover) { position: relative; z-index: 1; pointer-events: none; }
.gcard :deep(a), .gcard :deep(button) { pointer-events: auto; }
.invline { display: flex; align-items: center; gap: 8px; font-size: 13.5px; font-weight: 700; color: var(--ink); padding-bottom: 10px; border-bottom: 1px solid var(--line); }
.inv { border-color: var(--st-pending-soft); }
.ghd { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.where { min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.where h3 { font-family: var(--body); font-size: 17px; font-weight: 800; letter-spacing: -0.01em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.when { display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 600; color: var(--ink); }
.when .ico { color: var(--muted); }
.tbd { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; border: 1px dashed var(--line-2); color: var(--muted); font-size: 12.5px; font-weight: 700; }
.tags { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex: none; }
.fmt { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; background: var(--surface-2); border: 1px solid var(--line-2); font-weight: 800; font-size: 13px; white-space: nowrap; }
.note { margin: 0; font-size: 13.5px; color: var(--muted); overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.gfoot { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: auto; flex-wrap: wrap; }
.who { display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1; }
.by { font-size: 12.5px; color: var(--muted); font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.acts { display: flex; gap: 6px; margin-left: auto; }
.st-cancelled, .st-played, .st-archived { opacity: .75; }
</style>
