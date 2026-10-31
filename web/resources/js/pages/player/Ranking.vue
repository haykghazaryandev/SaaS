<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import Icon from '@/components/Icon.vue';
import RankList, { type RankRow } from '@/components/player/RankList.vue';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

// A club's whole ranking (from the place page): the podium, everybody by rating, and where I am.
defineProps<{ title: string; back: string; rows: RankRow[]; me: RankRow | null }>();
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('player.ranking.title') + ' · ' + title" />
        <div class="wrap rpage">
            <Link class="backlink" :href="back"><Icon name="back" size="sm" />{{ title }}</Link>
            <h1>{{ $t('player.ranking.title') }}</h1>
            <p class="muted">{{ $t('player.ranking.club_sub', { club: title, n: rows.length }) }}</p>
            <div v-if="me" class="mine" data-test="my-rank">{{ $t('player.ranking.my_place', { n: me.rank, r: me.rating }) }}</div>
            <RankList v-if="rows.length" :rows="rows" :me="me?.id" podium />
            <EmptyState v-else icon="trophy" :title="$t('player.ranking.empty_title')" :text="$t('player.ranking.empty_text')" />
        </div>
    </PlayerLayout>
</template>

<style scoped>
.rpage { max-width: 720px; padding-top: 16px; padding-bottom: 40px; }
.rpage h1 { margin: 10px 0 4px; }
.mine { display: inline-flex; margin: 4px 0 14px; padding: 6px 12px; border-radius: 999px; background: color-mix(in srgb, var(--accent) 14%, transparent); color: var(--accent); font-weight: 800; font-size: 13.5px; }
.backlink { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); font-weight: 700; }
</style>
