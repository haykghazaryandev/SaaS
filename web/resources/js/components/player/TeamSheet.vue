<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import { dateLong } from '@/composables/usePlayer';
import type { PlayerBooking } from '@/types/player';
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

// "Your team" of one booking: everyone who joined gets the booking's news and the reminders (24 h and 1 h before).
// Two ways: invite friends (they accept or decline, in Telegram or My bookings), or share the invite link in a
// group chat (opening it is joining: no answer needed). The booker can remove people or take back an invite here.
export interface FriendRow {
    id: number;
    name: string;
    username: string | null;
    avatar: string | null;
}
const props = defineProps<{ booking: PlayerBooking; friends: FriendRow[] }>();
const emit = defineEmits<{ close: [] }>();

const tab = ref<'friends' | 'link'>(props.friends.length ? 'friends' : 'link');
const team = computed(() => props.booking.team ?? []);
const active = computed(() => team.value.filter((p) => p.status !== 'declined'));
const joined = computed(() => team.value.filter((p) => p.status === 'joined').length);
const asked = computed(() => new Set(active.value.map((p) => p.user.id)));   // invited or joined: can't be invited again
const room = computed(() => Math.max(0, (props.booking.teamMax ?? 21) - active.value.length));

// ---------- friends ----------
const q = ref('');
const picked = ref<number[]>([]);
const shown = computed(() => {
    const s = q.value.trim().toLowerCase();
    return props.friends.filter((f) => !s || f.name.toLowerCase().includes(s) || (f.username ?? '').toLowerCase().includes(s));
});
function togglePick(id: number) {
    picked.value = picked.value.includes(id) ? picked.value.filter((x) => x !== id) : [...picked.value, id];
}
const friendsForm = useForm({ user_ids: [] as number[] });
function addFriends() {
    friendsForm.user_ids = picked.value;
    friendsForm.post(route('bookings.team', props.booking.id), { preserveScroll: true, onSuccess: () => (picked.value = []) });
}


// ---------- invite link ----------
const shareText = computed(() =>
    trans('player.team.shareText', { venue: props.booking.venue.name, when: `${dateLong(props.booking.date)} ${props.booking.start}` }),
);
const copied = ref(false);
async function copyLink() {
    if (!props.booking.inviteUrl) return;
    try {
        await navigator.clipboard.writeText(`${shareText.value} ${props.booking.inviteUrl}`);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        window.prompt(trans('player.team.copyManual'), props.booking.inviteUrl);
    }
}
const telegramShare = computed(() =>
    props.booking.inviteUrl ? `https://t.me/share/url?url=${encodeURIComponent(props.booking.inviteUrl)}&text=${encodeURIComponent(shareText.value)}` : null,
);
const whatsappShare = computed(() => (props.booking.inviteUrl ? `https://wa.me/?text=${encodeURIComponent(`${shareText.value} ${props.booking.inviteUrl}`)}` : null));
const canNativeShare = typeof navigator !== 'undefined' && 'share' in navigator;
function nativeShare() {
    navigator.share?.({ title: 'Bookball', text: shareText.value, url: props.booking.inviteUrl ?? undefined }).catch(() => {});
}

// errors about the booking itself (team full, not yours…) come back under "team"
const errOf = (f: { errors: object }, key: string) => (f.errors as Record<string, string | undefined>)[key];

function remove(id: number) {
    router.delete(route('bookings.team.destroy', { booking: props.booking.id, player: id }), { preserveScroll: true });
}
</script>

<template>
    <div class="scrim" @click.self="emit('close')">
        <div class="sheet team" role="dialog" aria-modal="true" aria-labelledby="teamT">
            <div class="sheet-head">
                <h3 id="teamT">{{ $t('player.team.title') }}</h3>
                <button type="button" class="x" :aria-label="$t('player.close')" @click="emit('close')"><Icon name="x" /></button>
            </div>
            <p class="muted small">{{ $t('player.team.why', { venue: booking.venue.name, when: `${dateLong(booking.date)} ${booking.start}` }) }}</p>

            <!-- who is in -->
            <ul v-if="team.length" class="members">
                <li v-for="p in team" :key="p.id">
                    <Avatar :id="p.user.id" :name="p.user.name" :src="p.user.avatar" size="sm" />
                    <span class="nm"
                        >{{ p.user.name }}
                        <small v-if="p.status === 'invited'" class="st warn">{{ $t('player.team.waitingAnswer') }}</small>
                        <small v-else-if="p.status === 'declined'" class="st">{{ $t('player.team.declinedPill') }}</small>
                    </span>
                    <button type="button" class="rm" :aria-label="$t('player.team.remove')" :title="$t('player.team.remove')" @click="remove(p.id)"><Icon name="x" size="sm" /></button>
                </li>
            </ul>
            <p class="muted small count">{{ $t('player.team.count', { n: String(joined + 1), max: String((booking.teamMax ?? 21) + 1) }) }}</p>

            <div class="seg block" role="tablist">
                <button type="button" role="tab" :aria-pressed="tab === 'friends'" @click="tab = 'friends'"><Icon name="users" size="sm" />{{ $t('player.team.tabFriends') }}</button>
                <button type="button" role="tab" :aria-pressed="tab === 'link'" @click="tab = 'link'"><Icon name="share" size="sm" />{{ $t('player.team.tabLink') }}</button>
            </div>

            <!-- friends -->
            <div v-if="tab === 'friends'" class="pane">
                <template v-if="friends.length">
                    <p class="muted small">{{ $t('player.team.friendsHint') }}</p>
                    <input v-model="q" class="input" type="search" :placeholder="$t('player.team.search')" />
                    <ul class="pick">
                        <li v-for="f in shown" :key="f.id">
                            <label :class="{ off: asked.has(f.id) }">
                                <input type="checkbox" :checked="asked.has(f.id) || picked.includes(f.id)" :disabled="asked.has(f.id) || (!picked.includes(f.id) && picked.length >= room)" @change="togglePick(f.id)" />
                                <Avatar :id="f.id" :name="f.name" :src="f.avatar" size="sm" />
                                <span class="nm">{{ f.name }}<small v-if="f.username" class="muted"> @{{ f.username }}</small></span>
                                <span v-if="asked.has(f.id)" class="muted small">{{ $t('player.team.alreadyAsked') }}</span>
                            </label>
                        </li>
                    </ul>
                    <div v-if="errOf(friendsForm, 'team')" class="err">{{ errOf(friendsForm, 'team') }}</div>
                    <button type="button" class="btn block" :disabled="!picked.length || friendsForm.processing" @click="addFriends">
                        <Icon name="send" size="sm" />{{ picked.length ? $t('player.team.inviteN', { n: String(picked.length) }) : $t('player.team.pickFriends') }}
                    </button>
                </template>
                <p v-else class="muted small">{{ $t('player.team.noFriends') }}</p>
            </div>


            <!-- invite link -->
            <div v-else class="pane">
                <p class="muted small">{{ $t('player.team.linkHint') }}</p>
                <div class="share">
                    <a v-if="telegramShare" class="btn ghost" :href="telegramShare" target="_blank" rel="noopener"><Icon name="send" size="sm" />Telegram</a>
                    <a v-if="whatsappShare" class="btn ghost" :href="whatsappShare" target="_blank" rel="noopener"><Icon name="msg" size="sm" />WhatsApp</a>
                    <button v-if="canNativeShare" type="button" class="btn ghost" @click="nativeShare"><Icon name="share" size="sm" />{{ $t('player.team.more') }}</button>
                    <button type="button" class="btn outline" @click="copyLink"><Icon :name="copied ? 'check' : 'share'" size="sm" />{{ copied ? $t('player.team.copied') : $t('player.team.copy') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.team { display: flex; flex-direction: column; gap: 12px; }
.small { font-size: 13.5px; margin: 0; }
.count { margin-top: -4px; }
.members, .pick { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.members li { display: flex; align-items: center; gap: 10px; padding: 6px 0; }
.nm { flex: 1; min-width: 0; font-weight: 700; font-size: 14px; overflow-wrap: anywhere; }
.nm .st { display: block; font-size: 12px; font-weight: 700; color: var(--muted); }
.nm .st.warn { color: var(--st-pending); }
.rm { flex: none; width: 30px; height: 30px; border-radius: 8px; border: 0; background: none; color: var(--muted); display: grid; place-items: center; }
.rm:hover { background: var(--surface-2); color: var(--hot); }
.seg button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
.pane { display: flex; flex-direction: column; gap: 10px; }
.pick { max-height: 40vh; overflow-y: auto; }
.pick label { display: flex; align-items: center; gap: 10px; padding: 8px 4px; border-radius: 10px; cursor: pointer; }
.pick label:hover { background: var(--surface-2); }
.pick label.off { cursor: default; opacity: .6; }
.pick input { width: 18px; height: 18px; accent-color: var(--accent); flex: none; }
.share { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.share .btn { justify-content: center; }
.err { color: var(--hot); font-size: 13px; }
</style>
