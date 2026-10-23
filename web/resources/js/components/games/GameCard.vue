<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { whenText, whereText } from '@/composables/useGames';
import type { GameCard } from '@/types/games';
import { Link } from '@inertiajs/vue3';
import Avatars from './Avatars.vue';
import GameProgress from './GameProgress.vue';
import GameStatusPill from './GameStatusPill.vue';

// A game in a list: format, status, level, place, time, players and an action slot (Join, Accept …).
defineProps<{ game: GameCard }>();
</script>

<template>
    <article :class="['card gcard', `st-${game.status}`]">
        <Link class="cover" :href="route('games.show', game.id)" :aria-label="game.format" />
        <div class="row between ghd">
            <span class="fmt">{{ game.format }}</span>
            <div class="row wrapflex" style="gap: 6px; justify-content: flex-end">
                <span v-if="game.mine" class="pill lime"><Icon name="star" size="sm" />{{ $t('games.card.yours') }}</span>
                <span v-else-if="game.me === 'accepted' || game.me === 'joined'" class="pill blue"><Icon name="check" size="sm" />{{ $t('games.card.youIn') }}</span>
                <GameStatusPill :status="game.status" />
            </div>
        </div>
        <div class="facts">
            <span><Icon name="pin" size="sm" />{{ whereText(game) }}</span>
            <span><Icon name="clock" size="sm" />{{ game.booking ? `${whenText({ date: game.booking.date, from: game.booking.start, to: game.booking.end })}` : whenText(game.window) }}</span>
            <span><Icon name="bolt" size="sm" />{{ game.level ? $t(`level.${game.level}`) : $t('games.anyLevel') }}</span>
        </div>
        <p v-if="game.note" class="note">“{{ game.note }}”</p>
        <GameProgress :in-count="game.in" :total="game.total" :min-to-open="game.minToOpen" :status="game.status" />
        <div class="row between gfoot">
            <div class="row" style="gap: 10px; min-width: 0">
                <Avatars :people="game.avatars" :more="game.in - game.avatars.length" />
                <span class="by">{{ $t('games.card.by', { name: game.creator.short }) }}</span>
            </div>
            <div class="acts"><slot /></div>
        </div>
    </article>
</template>

<style scoped>
.gcard { position: relative; padding: 18px; display: flex; flex-direction: column; gap: 12px; transition: transform .18s, border-color .18s; min-width: 0; }
.gcard:hover { transform: translateY(-2px); border-color: var(--line-2); }
.cover { position: absolute; inset: 0; z-index: 0; border-radius: inherit; }
.gcard > *:not(.cover) { position: relative; z-index: 1; pointer-events: none; }
.gcard :deep(a), .gcard :deep(button) { pointer-events: auto; }
.ghd { gap: 10px; align-items: flex-start; }
.fmt { font-family: var(--display); font-size: 26px; font-weight: 700; letter-spacing: -0.03em; line-height: 1; }
.st-open .fmt, .st-full .fmt { background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; }
.facts { display: flex; flex-wrap: wrap; gap: 6px 14px; color: var(--muted); font-size: 13.5px; }
.facts span { display: inline-flex; gap: 5px; align-items: center; min-width: 0; }
.note { margin: 0; font-size: 13.5px; color: var(--muted); overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.gfoot { gap: 10px; margin-top: auto; flex-wrap: wrap; }
.by { font-size: 12.5px; color: var(--faint); font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.acts { display: flex; gap: 6px; margin-left: auto; }
.st-cancelled, .st-played, .st-archived { opacity: .7; }
</style>
