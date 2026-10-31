<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// A ranking (the rating the clubs set): the first three on a podium (when asked), then the rows; my own row stands
// out. A row: the place (equal ratings share it), the player (to their profile), their club (all-clubs list), the rating.
export interface RankRow {
    rank: number;
    id: number;
    name: string | null;
    username: string | null;
    avatar: string | null;
    rating: number;
    intl?: string | null;
    club?: { id: number; name: string; slug: string } | null;
}
const props = defineProps<{ rows: RankRow[]; me?: number | null; podium?: boolean; showClub?: boolean }>();
const top = computed(() => (props.podium && props.rows.length >= 3 ? props.rows.slice(0, 3) : []));
const rest = computed(() => (top.value.length ? props.rows.slice(3) : props.rows));
const url = (r: RankRow) => route('users.show', { player: r.username || r.id });
const medal = (n: number) => (n === 1 ? '🥇' : n === 2 ? '🥈' : '🥉');
</script>

<template>
    <div class="ranks" data-test="ranking">
        <!-- the podium: 2nd · 1st · 3rd -->
        <div v-if="top.length" class="podium">
            <Link v-for="i in [1, 0, 2]" :key="top[i].id" :href="url(top[i])" :class="['pod', { first: i === 0, me: top[i].id === me }]">
                <Avatar :id="top[i].id" :name="top[i].name" :src="top[i].avatar" :size="i === 0 ? 'lg' : 'md'" />
                <b>{{ top[i].name }}</b>
                <small>{{ medal(top[i].rank) }} {{ top[i].rating }}</small>
            </Link>
        </div>
        <Link v-for="r in rest" :key="r.id" :href="url(r)" :class="['rrow', { me: r.id === me, top3: r.rank <= 3 }]">
            <span class="n">{{ r.rank }}</span>
            <Avatar :id="r.id" :name="r.name" :src="r.avatar" size="sm" />
            <span class="w"
                ><b>{{ r.id === me ? $t('player.ranking.you', { name: r.name ?? '' }) : r.name }}</b
                ><small v-if="showClub && r.club">{{ r.club.name }}</small><small v-else-if="r.intl">{{ r.intl }}</small></span
            >
            <b class="pt">{{ r.rating }}</b>
        </Link>
    </div>
</template>

<style scoped>
.podium { display: grid; grid-template-columns: 1fr 1.15fr 1fr; gap: 8px; align-items: end; margin-bottom: 12px; }
.pod { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 12px 6px 10px; border-radius: 18px; background: var(--surface); border: 1px solid var(--line-2); text-align: center; min-width: 0; color: var(--ink); text-decoration: none; }
.pod.first { padding-top: 18px; border-color: color-mix(in srgb, var(--accent) 55%, transparent); background: linear-gradient(180deg, color-mix(in srgb, var(--accent) 10%, transparent), var(--surface) 70%); }
.pod b { font-size: 13.5px; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pod small { color: var(--muted); font-weight: 800; font-size: 12.5px; }
.pod.me { outline: 2px solid var(--accent); }
.rrow { display: grid; grid-template-columns: 30px 32px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 9px 8px; border-bottom: 1px solid var(--line); color: var(--ink); text-decoration: none; }
.rrow:hover { background: var(--surface-2); }
.rrow .n { font-weight: 900; color: var(--muted); text-align: center; }
.rrow.top3 .n { color: var(--accent); }
.rrow .w { min-width: 0; display: flex; flex-direction: column; }
.rrow .w b { font-size: 14.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rrow .w small { color: var(--muted); font-size: 12px; }
.rrow .pt { font-family: var(--mono); font-size: 15px; }
.rrow.me { background: color-mix(in srgb, var(--accent) 10%, transparent); border-radius: 12px; border-bottom-color: transparent; }
</style>
