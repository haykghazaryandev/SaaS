<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import { ask } from '@/composables/useConfirm';
import { useOwner } from '@/composables/useOwner';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { trans } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/vue3';

// Team (the owner): the place's one owner and its staff. Staff: the daily work in the staff panel (Now, Today,
// Schedule, players, a scanned card, running tournaments, chats) — no money, no settings. The owner adds staff by name
// and phone (logging in with that phone opens the staff panel) or takes them off.
interface Person { id: number; name: string | null; phone: string | null; avatar: string | null; role: 'owner' | 'staff'; me: boolean; online: boolean; telegram: boolean }
defineProps<{ people: Person[] }>();
const owner = useOwner();
const form = useForm({ name: '', phone: '' });
const add = () => form.post(route('owner.team.store'), { preserveScroll: true, onSuccess: () => form.reset() });
async function remove(p: Person) {
    if (await ask({ title: trans('owner.team.ask_remove', { name: p.name || p.phone || '' }), text: trans('owner.team.ask_remove_text'), ok: trans('owner.team.remove'), danger: true }))
        router.delete(route('owner.team.destroy', p.id), { preserveScroll: true });
}
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.team')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.team') }}</h1>
            </div>
        </div>
        <div class="tgrid">
            <section class="card panel" data-test="team">
                <div v-for="p in people" :key="p.id" class="tp">
                    <Avatar :id="p.id" :name="p.name" :src="p.avatar" />
                    <span class="w"
                        ><b>{{ p.name || '—' }}<span v-if="p.me" class="faint"> · {{ $t('owner.team.you') }}</span><i v-if="p.online" class="on" :title="$t('owner.team.online')" /></b
                        ><small>{{ p.phone }}<template v-if="!p.telegram"> · {{ $t('owner.team.no_telegram') }}</template></small></span
                    >
                    <span :class="['rpill', p.role]">{{ $t('owner.team.role_' + p.role) }}</span>
                    <button v-if="p.role === 'staff'" type="button" class="x" :title="$t('owner.team.remove')" :aria-label="$t('owner.team.remove')" @click="remove(p)"><Icon name="trash" size="sm" /></button>
                </div>
            </section>
            <aside class="side">
                <form class="card panel" @submit.prevent="add">
                    <div class="sec-h"><h2>{{ $t('owner.team.add_staff') }}</h2></div>
                    <div class="field">
                        <label for="tp-name">{{ $t('owner.team.staff_name') }}</label>
                        <input id="tp-name" v-model="form.name" class="input" maxlength="80" required data-test="team-name" />
                    </div>
                    <div class="field">
                        <label for="tp-phone">{{ $t('common.phone') }}</label>
                        <input id="tp-phone" v-model="form.phone" class="input" type="tel" inputmode="tel" placeholder="+374 …" required data-test="team-phone" />
                        <small v-if="form.errors.phone || form.errors.name || (form.errors as Record<string, string>).booking" class="err">{{
                            form.errors.phone || form.errors.name || (form.errors as Record<string, string>).booking
                        }}</small>
                    </div>
                    <p class="faint" style="font-size: 12.5px; margin: 0 0 12px">{{ $t('owner.team.login_hint') }}</p>
                    <button type="submit" class="btn" :disabled="form.processing || !form.phone || !form.name" data-test="team-add">{{ $t('owner.team.add_btn') }}</button>
                </form>
                <div class="card panel roles">
                    <p><b>{{ $t('owner.team.role_staff') }}</b> — {{ $t('owner.team.staff_can') }}</p>
                    <p><b>{{ $t('owner.team.role_owner') }}</b> — {{ $t('owner.team.owner_can') }}</p>
                </div>
            </aside>
        </div>
    </OwnerLayout>
</template>

<style scoped>
.tgrid { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 14px; align-items: start; }
@media (max-width: 900px) { .tgrid { grid-template-columns: minmax(0, 1fr); } }
.tp { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--line); }
.tp .w { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.tp .w b { display: flex; align-items: center; gap: 6px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tp .w small { color: var(--muted); font-size: 12.5px; }
.on { width: 8px; height: 8px; border-radius: 50%; background: var(--free); display: inline-block; }
.rpill { flex-shrink: 0; font-size: 12px; font-weight: 800; padding: 4px 10px; border-radius: 999px; background: var(--surface-2); color: var(--muted); }
.rpill.owner { background: rgba(200, 255, 61, 0.14); color: var(--accent); }
.side { display: flex; flex-direction: column; gap: 14px; }
.roles p { margin: 0 0 8px; font-size: 13.5px; color: var(--muted); }
.roles b { color: var(--ink); }
.err { color: var(--hot); }
</style>
