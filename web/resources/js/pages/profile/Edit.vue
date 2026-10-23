<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import PlayerAvatar from '@/components/social/PlayerAvatar.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import { useShared } from '@/composables/useShared';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { PlayerCard } from '@/types/social';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// My profile: what other players see, language, Telegram, blocked players, log out.
const props = defineProps<{
    profile: {
        name: string | null;
        username: string | null;
        level: string | null;
        position: string | null;
        district: string | null;
        bio: string | null;
        locale: string;
        phone: string;
        telegram: boolean;
        url: string;
    };
    options: { levels: string[]; positions: string[]; districts: string[]; locales: string[] };
    blocked: PlayerCard[];
}>();

const { user } = useShared();
const form = useForm({
    name: props.profile.name ?? '',
    username: props.profile.username ?? '',
    level: props.profile.level,
    position: props.profile.position,
    district: props.profile.district ?? '',
    bio: props.profile.bio ?? '',
    locale: props.profile.locale,
});
const bioLeft = computed(() => 300 - form.bio.length);
const canLinkTelegram = (() => {
    try {
        return route().has('telegram.link');
    } catch {
        return false;
    }
})();

function save() {
    const langChanged = form.locale !== props.profile.locale;
    form.transform((d) => ({ ...d, username: d.username.trim() || null, district: d.district || null, bio: d.bio.trim() || null })).patch(route('me.profile.update'), {
        preserveScroll: true,
        // a new language needs its texts: reload the page
        onSuccess: () => {
            if (langChanged) window.location.reload();
        },
    });
}
const toggle = (key: 'level' | 'position', v: string) => (form[key] = form[key] === v ? null : v);
const unblocking = ref<number | null>(null);
function unblock(p: PlayerCard) {
    unblocking.value = p.id;
    router.delete(route('users.unblock', p.id), { preserveScroll: true, onFinish: () => (unblocking.value = null) });
}
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('profile.title')" />

        <section class="phead">
            <PlayerAvatar :id="user?.id ?? 0" :name="form.name || profile.name" :size="64" />
            <div style="min-width: 0">
                <span class="kicker">{{ $t('profile.kick') }}</span>
                <h1>{{ profile.name || profile.phone }}</h1>
                <Link :href="profile.url" class="see"><Icon name="eye" size="sm" />{{ $t('profile.public') }}</Link>
            </div>
        </section>

        <div class="cols">
            <form class="card pform" @submit.prevent="save">
                <p class="muted lead">{{ $t('profile.lead') }}</p>
                <div class="two">
                    <div class="field">
                        <label for="pf-name">{{ $t('profile.name') }}</label>
                        <input id="pf-name" v-model="form.name" class="input" autocomplete="name" maxlength="60" required />
                        <div v-if="form.errors.name" class="err">{{ form.errors.name }}</div>
                    </div>
                    <div class="field">
                        <label for="pf-user">{{ $t('profile.username') }}</label>
                        <div class="at">
                            <span>@</span>
                            <input id="pf-user" v-model="form.username" class="input mono" autocapitalize="off" autocomplete="off" spellcheck="false" maxlength="32" placeholder="aram_10" />
                        </div>
                        <div v-if="form.errors.username" class="err">{{ form.errors.username }}</div>
                        <small v-else class="faint">{{ $t('profile.usernameHint') }}</small>
                    </div>
                </div>

                <div class="field">
                    <label>{{ $t('profile.level') }}</label>
                    <div class="chips wrapchips">
                        <button v-for="l in options.levels" :key="l" type="button" class="chip" :aria-pressed="form.level === l" @click="toggle('level', l)">{{ $t('level.' + l) }}</button>
                    </div>
                    <div v-if="form.errors.level" class="err">{{ form.errors.level }}</div>
                </div>

                <div class="field">
                    <label>{{ $t('profile.position') }}</label>
                    <div class="chips wrapchips">
                        <button v-for="p in options.positions" :key="p" type="button" class="chip" :aria-pressed="form.position === p" @click="toggle('position', p)">{{ $t('profile.positions.' + p) }}</button>
                    </div>
                    <div v-if="form.errors.position" class="err">{{ form.errors.position }}</div>
                </div>

                <div class="two">
                    <div class="field">
                        <label for="pf-district">{{ $t('profile.district') }}</label>
                        <select id="pf-district" v-model="form.district" class="input">
                            <option value="">{{ $t('profile.notSet') }}</option>
                            <option v-for="d in options.districts" :key="d" :value="d">{{ $t('player.district.' + d) }}</option>
                        </select>
                        <div v-if="form.errors.district" class="err">{{ form.errors.district }}</div>
                    </div>
                    <div class="field">
                        <label>{{ $t('profile.language') }}</label>
                        <div class="seg" role="radiogroup" :aria-label="$t('profile.language')">
                            <button v-for="l in options.locales" :key="l" type="button" role="radio" :aria-checked="form.locale === l" @click="form.locale = l">{{ $t('profile.languages.' + l) }}</button>
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label for="pf-bio">{{ $t('profile.bio') }}</label>
                    <textarea id="pf-bio" v-model="form.bio" class="input" rows="3" maxlength="300" :placeholder="$t('profile.bioPlaceholder')" />
                    <div v-if="form.errors.bio" class="err">{{ form.errors.bio }}</div>
                    <small v-else class="faint" style="text-align: right">{{ bioLeft }} / 300</small>
                </div>

                <div class="row" style="justify-content: flex-end">
                    <button class="btn" :disabled="form.processing || !form.isDirty"><Icon name="check" size="sm" />{{ $t('profile.save') }}</button>
                </div>
            </form>

            <aside class="side">
                <section class="card box">
                    <div class="row between">
                        <h2>{{ $t('profile.phone') }}</h2>
                        <span class="mono muted">{{ profile.phone }}</span>
                    </div>
                </section>

                <section v-if="profile.telegram" class="card box">
                    <h2>{{ $t('profile.telegram') }}</h2>
                    <p class="muted small"><span class="pill blue" style="margin-right: 6px"><Icon name="send" size="sm" /></span>{{ $t('profile.telegramOn') }}</p>
                </section>
                <TelegramConnect v-else-if="canLinkTelegram" class="tg" />

                <section class="card box">
                    <h2>{{ $t('profile.blocked') }}</h2>
                    <ul v-if="blocked.length" class="blist">
                        <li v-for="p in blocked" :key="p.id">
                            <PlayerAvatar :id="p.id" :name="p.name" :size="34" />
                            <Link :href="p.url" class="bn">{{ p.name }}</Link>
                            <button type="button" class="btn ghost sm" :disabled="unblocking === p.id" @click="unblock(p)">{{ $t('friends.unblock') }}</button>
                        </li>
                    </ul>
                    <p v-else class="muted small">{{ $t('profile.blockedEmpty') }}</p>
                </section>

                <Link :href="route('logout')" method="post" as="button" class="btn ghost block out"><Icon name="logout" size="sm" />{{ $t('profile.logout') }}</Link>
            </aside>
        </div>
    </PlayerLayout>
</template>

<style scoped>
.phead { margin-top: 28px; display: flex; gap: 16px; align-items: center; }
.phead h1 { font-size: clamp(22px, 3vw, 30px); margin: 2px 0 2px; overflow-wrap: anywhere; }
.see { display: inline-flex; gap: 6px; align-items: center; color: var(--muted); font-weight: 700; font-size: 13px; }
.see:hover { color: var(--accent); }
.cols { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; margin-top: 22px; align-items: start; }
.pform { padding: 22px; display: flex; flex-direction: column; gap: 18px; }
.lead { margin: 0; font-size: 14px; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.at { position: relative; }
.at span { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--muted); font-family: var(--mono); }
.at .input { padding-left: 30px; }
.wrapchips { flex-wrap: wrap; overflow: visible; }
.seg { display: flex; background: var(--bg-2); border: 1px solid var(--line-2); border-radius: var(--r-sm); padding: 3px; gap: 3px; }
.seg button { flex: 1; min-width: 0; border: 0; background: none; color: var(--muted); padding: 8px 6px; border-radius: 8px; font-weight: 700; font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.seg button[aria-checked="true"] { background: var(--surface-3); color: var(--ink); }
textarea.input { resize: vertical; min-height: 84px; }
.err { color: var(--hot); font-size: 13px; }
small { font-size: 12px; }
.side { display: flex; flex-direction: column; gap: 12px; }
.box { padding: 16px 18px; }
.box h2 { font-size: 15px; margin: 0; }
.small { font-size: 13.5px; margin: 8px 0 0; }
.tg { border-color: rgba(92, 200, 255, 0.22); }
.blist { list-style: none; margin: 10px 0 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.blist li { display: flex; align-items: center; gap: 10px; }
.blist .bn { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 700; font-size: 14px; }
.out { color: var(--muted); }
.out:hover { color: var(--hot); }
@media (max-width: 900px) { .cols { grid-template-columns: 1fr; } }
@media (max-width: 560px) { .two { grid-template-columns: 1fr; } .pform { padding: 18px; } }
</style>
