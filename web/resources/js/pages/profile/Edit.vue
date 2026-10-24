<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import Avatar from '@/components/Avatar.vue';
import AvatarCropper from '@/components/AvatarCropper.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import { useShared } from '@/composables/useShared';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { PlayerCard } from '@/types/social';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

// ---------- profile photo: pick → position it in the circle (move / zoom) → upload ----------
const photo = useForm<{ photo: File | null }>({ photo: null });
const picked = ref<File | null>(null);
function upload(e: Event) {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file) picked.value = file;
}
function cropped(blob: Blob) {
    photo.photo = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
    photo.post(route('me.avatar'), { preserveScroll: true, forceFormData: true, onSuccess: () => (picked.value = null) });
}
function removePhoto() {
    router.delete(route('me.avatar.destroy'), { preserveScroll: true });
}

// My profile: what other players see, language, Telegram, blocked players, log out.
const props = defineProps<{
    profile: {
        name: string | null;
        username: string | null;
        position: string | null;
        district: string | null;
        bio: string | null;
        locale: string;
        phone: string;
        telegram: boolean;
        url: string;
    };
    options: { positions: string[]; districts: string[]; locales: string[] };
    notify: Record<string, boolean>;
    blocked: PlayerCard[];
}>();

const { user } = useShared();

// ---------- notifications ----------
const notifs = reactive({ ...props.notify });
const notifKeys = computed(() => ['bookings', 'games', 'friends', 'chats', 'alerts', ...(user.value?.isOwner ? ['requests'] : []), 'sound']);
function setNotif(key: string, on: boolean) {
    notifs[key] = on;
    router.patch(route('me.notifications'), { key, on }, { preserveScroll: true, preserveState: true, onError: () => (notifs[key] = !on) });
}
const form = useForm({
    name: props.profile.name ?? '',
    username: props.profile.username ?? '',
    position: props.profile.position,
    district: props.profile.district ?? '',
    bio: props.profile.bio ?? '',
    locale: props.profile.locale,
});

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
const toggle = (key: 'position', v: string) => (form[key] = form[key] === v ? null : v);
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
            <!-- profile photo: tap the picture or the button to upload; we crop it to a square -->
            <label class="phwrap" :title="$t(user?.avatar ? 'profile.photo.change' : 'profile.photo.add')">
                <Avatar :id="user?.id ?? null" :name="form.name || profile.name" :src="user?.avatar" size="xl" />
                <span class="phcam"><Icon name="plus" size="sm" /></span>
                <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="upload" />
            </label>
            <div style="min-width: 0">
                <h1 class="page-title">{{ profile.name || profile.phone }}</h1>
                <Link :href="profile.url" class="see"><Icon name="eye" size="sm" />{{ $t('profile.public') }}</Link>
                <div class="phacts">
                    <label class="btn outline sm">
                        {{ photo.processing ? $t('profile.photo.uploading') : $t(user?.avatar ? 'profile.photo.change' : 'profile.photo.add') }}
                        <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="upload" />
                    </label>
                    <button v-if="user?.avatar" type="button" class="btn link danger sm" @click="removePhoto">{{ $t('common.remove') }}</button>
                </div>
                <div v-if="photo.errors.photo" class="err">{{ photo.errors.photo }}</div>
                <div v-else class="hint">{{ $t('profile.photo.hint') }}</div>
            </div>
        </section>

        <div class="cols">
            <form class="card pform" @submit.prevent="save">
                <p class="muted lead">{{ $t('profile.lead') }}</p>
                <div class="two">
                    <div class="field">
                        <label for="pf-name">{{ $t('common.name') }}</label>
                        <input id="pf-name" v-model="form.name" class="input" autocomplete="name" maxlength="60" required />
                        <div v-if="form.errors.name" class="err">{{ form.errors.name }}</div>
                    </div>
                    <div class="field">
                        <label for="pf-user">{{ $t('profile.username') }}</label>
                        <div class="at">
                            <span>@</span>
                            <input id="pf-user" v-model="form.username" class="input" autocapitalize="off" autocomplete="off" spellcheck="false" maxlength="32" placeholder="aram_10" />
                        </div>
                        <div v-if="form.errors.username" class="err">{{ form.errors.username }}</div>
                        <small v-else class="faint">{{ $t('profile.username_hint') }}</small>
                    </div>
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
                        <label for="pf-district">{{ $t('common.district') }}</label>
                        <select id="pf-district" v-model="form.district" class="input">
                            <option value="">{{ $t('profile.not_set') }}</option>
                            <option v-for="d in options.districts" :key="d" :value="d">{{ $t('common.districts.' + d) }}</option>
                        </select>
                        <div v-if="form.errors.district" class="err">{{ form.errors.district }}</div>
                    </div>
                    <div class="field">
                        <label>{{ $t('common.language') }}</label>
                        <div class="seg block" role="radiogroup" :aria-label="$t('common.language')">
                            <button v-for="l in options.locales" :key="l" type="button" role="radio" :aria-checked="form.locale === l" @click="form.locale = l">{{ $t('profile.languages.' + l) }}</button>
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label for="pf-bio">{{ $t('profile.bio') }}</label>
                    <textarea id="pf-bio" v-model="form.bio" class="input" rows="3" maxlength="300" :placeholder="$t('profile.bio_placeholder')" />
                    <div v-if="form.errors.bio" class="err">{{ form.errors.bio }}</div>
                    <small v-else :class="['bcount', { near: form.bio.length > 280 }]">{{ form.bio.length }}/300</small>
                </div>

                <div class="savebar">
                    <span v-if="!form.isDirty && !form.recentlySuccessful" class="muted small0">{{ $t('profile.no_changes') }}</span>
                    <span v-else-if="form.recentlySuccessful && !form.isDirty" class="okt small0"><Icon name="check" size="sm" />{{ $t('profile.saved') }}</span>
                    <button class="btn" :disabled="form.processing || !form.isDirty">{{ $t('common.save') }}</button>
                </div>
            </form>

            <aside class="side">
                <section class="card box">
                    <div class="row between">
                        <h2>{{ $t('common.phone') }}</h2>
                        <span class="muted">{{ profile.phone }}</span>
                    </div>
                </section>

                <section v-if="profile.telegram" class="card box">
                    <h2>{{ $t('profile.telegram') }}</h2>
                    <p class="muted small"><span class="pill blue" style="margin-right: 6px"><Icon name="send" size="sm" /></span>{{ $t('profile.telegram_on') }}</p>
                </section>
                <TelegramConnect v-else-if="canLinkTelegram" class="tg" />

                <section class="card box">
                    <h2>{{ $t('profile.blocked') }}</h2>
                    <ul v-if="blocked.length" class="blist">
                        <li v-for="p in blocked" :key="p.id">
                            <Avatar :id="p.id" :name="p.name" :src="p.avatar" size="sm" />
                            <Link :href="p.url" class="bn">{{ p.name }}</Link>
                            <button type="button" class="btn outline sm" :disabled="unblocking === p.id" @click="unblock(p)">{{ $t('common.unblock') }}</button>
                        </li>
                    </ul>
                    <p v-else class="muted small">{{ $t('profile.blocked_empty') }}</p>
                </section>
            </aside>
        </div>

        <!-- notifications: what comes by Telegram (per kind), and the sound in the app; each switch saves at once -->
        <section class="card pad notif">
            <h2>{{ $t('profile.notifications.title') }}</h2>
            <p class="muted small">{{ profile.telegram ? $t('profile.notifications.sub') : $t('profile.notifications.no_telegram') }}</p>
            <label v-for="k in notifKeys" :key="k" class="sw">
                <span><b>{{ $t(`profile.notifications.${k}`) }}</b><small class="muted">{{ $t(`profile.notifications.${k}_help`) }}</small></span>
                <input type="checkbox" role="switch" :checked="notifs[k]" :disabled="!profile.telegram && k !== 'sound'" @change="setNotif(k, ($event.target as HTMLInputElement).checked)" />
            </label>
        </section>

        <!-- owners and the admin: their panels (on phones and in the Telegram Mini App there is no other way in) -->
        <section v-if="user?.isOwner || user?.isAdmin" class="card pad panels">
            <h2>{{ $t('profile.panels') }}</h2>
            <Link v-if="user?.isOwner" class="btn ghost block" :href="route('owner.today')"><Icon name="chart" size="sm" />{{ $t('profile.owner_panel') }}</Link>
            <!-- the admin panel is not part of this app (Filament): a full page load -->
            <a v-if="user?.isAdmin" class="btn ghost block" href="/admin"><Icon name="settings" size="sm" />{{ $t('profile.admin_panel') }}</a>
        </section>

        <div class="outrow">
            <Link :href="route('logout')" method="post" as="button" class="btn outline out"><Icon name="logout" size="sm" />{{ $t('common.log_out') }}</Link>
        </div>
        <AvatarCropper v-if="picked" :file="picked" :saving="photo.processing" @done="cropped" @cancel="picked = null" />
    </PlayerLayout>
</template>

<style scoped>
.notif { max-width: 820px; margin-top: 16px; display: flex; flex-direction: column; gap: 4px; }
.notif h2 { font-size: 17px; margin: 0; }
.notif > p { margin: 0 0 8px; }
.sw { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 10px 0; border-top: 1px solid var(--line); cursor: pointer; }
.sw span { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.sw small { font-size: 12.5px; }
.sw input { appearance: none; -webkit-appearance: none; flex: none; width: 46px; height: 28px; border-radius: 999px; background: var(--surface-3); position: relative; cursor: pointer; transition: background .15s; }
.sw input::after { content: ''; position: absolute; top: 3px; left: 3px; width: 22px; height: 22px; border-radius: 50%; background: var(--ink); transition: transform .15s; }
.sw input:checked { background: var(--accent); }
.sw input:checked::after { transform: translateX(18px); background: var(--on-accent); }
.sw input:disabled { opacity: .4; }
.panels { max-width: 820px; margin-top: 16px; display: flex; flex-direction: column; gap: 10px; }
.panels h2 { font-size: 17px; margin: 0 0 4px; }
.phead { margin-top: 28px; display: flex; gap: 16px; align-items: center; }
.phead h1 { font-size: clamp(22px, 3vw, 30px); margin: 2px 0 2px; overflow-wrap: anywhere; }
.see { display: inline-flex; gap: 6px; align-items: center; color: var(--muted); font-weight: 700; font-size: 13px; }
.see:hover { color: var(--accent); }
.cols { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; margin-top: 22px; align-items: start; }
.pform { padding: 22px; display: flex; flex-direction: column; gap: 18px; }
.lead { margin: 0; font-size: 14px; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.at { position: relative; }
.at span { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--muted); }
.at .input { padding-left: 30px; }
.wrapchips { flex-wrap: wrap; overflow: visible; }
.seg button { padding-left: 8px; padding-right: 8px; min-height: 38px; }
.seg button[aria-checked="true"] { background: var(--surface-3); color: var(--ink); }
textarea.input { resize: vertical; min-height: 84px; }
.err { color: var(--hot); font-size: 13px; }
small { font-size: 12px; }
.side { display: flex; flex-direction: column; gap: 12px; }
.box { padding: 16px 18px; }
.box h2 { font-family: var(--body); font-size: 15px; font-weight: 800; letter-spacing: 0; margin: 0; }
.small { font-size: 13.5px; margin: 8px 0 0; }
.tg { border-color: rgba(92, 200, 255, 0.22); }
.blist { list-style: none; margin: 10px 0 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.blist li { display: flex; align-items: center; gap: 10px; }
.blist .bn { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 700; font-size: 14px; }
.savebar { display: flex; align-items: center; justify-content: flex-end; gap: 14px; flex-wrap: wrap; }
.small0 { font-size: 13.5px; display: inline-flex; align-items: center; gap: 6px; }
.okt { color: var(--st-confirmed); font-weight: 700; }
.bcount { text-align: right; color: var(--muted); font-variant-numeric: tabular-nums; }
.bcount.near { color: var(--st-pending); }
.outrow { margin: 28px 0 8px; padding-top: 20px; border-top: 1px solid var(--line); display: flex; justify-content: center; }
.out { color: var(--muted); min-width: 200px; }
.out:hover { color: var(--hot); border-color: rgba(255, 107, 107, 0.35); }
@media (max-width: 600px) { .out { width: 100%; } .savebar .btn { flex: 1; } }
@media (max-width: 900px) { .cols { grid-template-columns: 1fr; } }
@media (max-width: 560px) { .two { grid-template-columns: 1fr; } .pform { padding: 18px; } }

.phwrap { position: relative; cursor: pointer; flex: none; border-radius: 50%; }
.phwrap:focus-within { outline: 2px solid var(--accent); outline-offset: 3px; }
.phcam { position: absolute; right: 0; bottom: 0; width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; background: var(--accent); color: var(--on-accent); box-shadow: 0 0 0 3px var(--bg); }
.phacts { display: flex; gap: 8px; align-items: center; margin-top: 10px; flex-wrap: wrap; }
.phacts label.btn { cursor: pointer; }
</style>
