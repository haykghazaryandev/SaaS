<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import type { PlayerCard } from '@/types/social';
import { Link } from '@inertiajs/vue3';

// One player in a list: a compact 64px row (avatar, name, @username); tapping the row opens the profile.
// The default slot holds trailing actions (chat icon, Accept …); the "extra" slot adds a line under the name.
defineProps<{ player: PlayerCard }>();
</script>

<template>
    <article class="list-row prow">
        <Link :href="player.url" class="cover" :aria-label="player.name" />
        <Avatar :id="player.id" :name="player.name" :src="player.avatar" size="md" />
        <span class="lr-main">
            <b>{{ player.name }}</b>
            <small v-if="player.username || $slots.extra"><template v-if="player.username">@{{ player.username }}</template><slot name="extra" /></small>
        </span>
        <div class="pact"><slot /></div>
    </article>
</template>

<style scoped>
.prow { position: relative; background: var(--surface); border: 1px solid var(--line); min-width: 0; }
.prow:hover { border-color: var(--line-2); }
.cover { position: absolute; inset: 0; z-index: 0; border-radius: inherit; }
.prow > :not(.cover) { position: relative; z-index: 1; pointer-events: none; }
.prow :deep(a), .prow :deep(button) { pointer-events: auto; }
.lr-main b { font-size: 15px; }
.pact { flex: none; display: flex; gap: 6px; align-items: center; }
</style>
