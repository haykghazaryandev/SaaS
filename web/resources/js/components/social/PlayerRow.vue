<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import PlayerAvatar from '@/components/social/PlayerAvatar.vue';
import type { PlayerCard } from '@/types/social';
import { Link } from '@inertiajs/vue3';

// One player in a list: avatar, name, @username, level / position / district, and actions on the right.
defineProps<{ player: PlayerCard }>();
</script>

<template>
    <article class="card prow">
        <Link :href="player.url" class="pmain">
            <PlayerAvatar :id="player.id" :name="player.name" />
            <span class="ptxt">
                <b>{{ player.name }}</b>
                <span class="psub">
                    <span v-if="player.username" class="mono">@{{ player.username }}</span>
                    <span v-if="player.level">{{ $t('level.' + player.level) }}</span>
                    <span v-if="player.position">{{ $t('profile.positions.' + player.position) }}</span>
                    <span v-if="player.district"><Icon name="pin" size="sm" />{{ $t('player.district.' + player.district) }}</span>
                </span>
                <slot name="extra" />
            </span>
        </Link>
        <div class="pact"><slot /></div>
    </article>
</template>

<style scoped>
.prow { display: flex; align-items: center; gap: 14px; padding: 12px 14px; }
.pmain { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1; }
.pmain:hover b { color: var(--accent); }
.ptxt { display: flex; flex-direction: column; min-width: 0; gap: 2px; }
.ptxt b { font-size: 15px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.psub { display: flex; flex-wrap: wrap; gap: 2px 10px; color: var(--muted); font-size: 12.5px; }
.psub span { display: inline-flex; gap: 4px; align-items: center; }
.psub .mono { font-size: 12px; }
.pact { flex: none; display: flex; gap: 6px; align-items: center; }
@media (max-width: 520px) {
  .prow { flex-wrap: wrap; gap: 10px; }
  .pact { width: 100%; justify-content: flex-end; }
}
</style>
