<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { dateLong } from '@/composables/usePlayer';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

// "How was the field?" after a played game (the Telegram message has the same stars as buttons).
// Stars are required, a comment is optional; the answer can be changed for 7 days.
const props = defineProps<{
    booking: { id: number; venue: string; slug: string; field: string; date: string; start: string };
    canRate: boolean;
    review: { stars: number; comment: string | null } | null;
}>();

const form = useForm({ stars: props.review?.stars ?? 0, comment: props.review?.comment ?? '' });
const hover = ref(0);
const done = ref(!!props.review);
function send() {
    form.post(route('rate.store', props.booking.id), { preserveScroll: true, onSuccess: () => (done.value = true) });
}
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('player.rate.title', { venue: booking.venue })" />
        <div class="card pad rate">
            <h1 class="page-title">{{ $t('player.rate.title', { venue: booking.venue }) }}</h1>
            <p class="muted">{{ booking.field }} · {{ dateLong(booking.date) }} · {{ booking.start }}</p>

            <template v-if="canRate">
                <div class="stars" role="radiogroup" :aria-label="$t('player.rate.stars')" @mouseleave="hover = 0">
                    <button
                        v-for="n in 5"
                        :key="n"
                        type="button"
                        role="radio"
                        :aria-checked="form.stars === n"
                        :aria-label="$t('player.rate.n', { n: String(n) })"
                        :class="{ on: n <= (hover || form.stars) }"
                        @mouseenter="hover = n"
                        @click="(form.stars = n), (done = false)"
                    >
                        <Icon name="star" />
                    </button>
                </div>
                <div class="field">
                    <label for="c">{{ $t('player.rate.comment') }}</label>
                    <textarea id="c" v-model="form.comment" class="input" rows="3" maxlength="500" :placeholder="$t('player.rate.commentHint')" @input="done = false" />
                </div>
                <div v-if="form.errors.stars" class="err">{{ form.errors.stars }}</div>
                <p v-if="done" class="ok"><Icon name="check" size="sm" />{{ $t('player.rate.thanks') }}</p>
                <button v-else type="button" class="btn block" :disabled="!form.stars || form.processing" @click="send">{{ $t('player.rate.send') }}</button>
            </template>
            <p v-else class="muted">{{ $t('player.rate.closed') }}</p>

            <Link class="btn link" :href="route('places.show', { venue: booking.slug })">{{ $t('player.rate.again') }} <Icon name="arrow" size="sm" /></Link>
        </div>
    </PlayerLayout>
</template>

<style scoped>
.rate { max-width: 520px; margin: 24px auto; display: flex; flex-direction: column; gap: 14px; }
.rate h1 { font-size: clamp(22px, 3vw, 28px); margin: 0; }
.rate > p { margin: -6px 0 0; }
.stars { display: flex; gap: 6px; }
.stars button { width: 48px; height: 48px; border: 0; border-radius: 12px; background: var(--surface-2); color: var(--faint); display: grid; place-items: center; }
.stars button.on { color: var(--warn); }
.stars button.on :deep(svg) { fill: currentColor; }
.ok { display: flex; gap: 6px; align-items: center; color: var(--st-confirmed); font-weight: 700; margin: 0; }
.err { color: var(--hot); font-size: 13px; }
</style>
