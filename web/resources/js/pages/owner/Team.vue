<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import { ask } from '@/composables/useConfirm';
import { useOwner } from '@/composables/useOwner';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { trans } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/vue3';

// Team (the owner): who works at the place. Owner: everything. Staff: the daily work (Now, Today, Schedule, players,
// a scanned card, running tournaments, chats) — no money, no settings. Add someone by phone (they log in with it),
// change the role, take them off. A place keeps at least one owner; nobody takes themselves off.
interface Person { id: number; name: string | null; phone: string | null; avatar: string | null; role: 'owner' | 'staff'; me: boolean; online: boolean; telegram: boolean }
defineProps<{ people: Person[] }>();
const owner = useOwner();
const form = useForm({ phone: '', name: '', role: 'staff' as 'owner' | 'staff' });
const add = () => form.post(route('owner.team.store'), { preserveScroll: true, onSuccess: () => form.reset() });
function setRole(p: Person, role: 'owner' | 'staff') {
    if (p.role !== role) router.post(route('owner.team.store'), { phone: p.phone, role }, { preserveScroll: true });
}
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
                    <div class="seg sm role" role="group" :aria-label="$t('owner.team.role')">
                        <button type="button" :aria-pressed="p.role === 'owner'" :disabled="p.me" @click="setRole(p, 'owner')">{{ $t('owner.team.role_owner') }}</button>
                        <button type="button" :aria-pressed="p.role === 'staff'" :disabled="p.me" @click="setRole(p, 'staff')">{{ $t('owner.team.role_staff') }}</button>
                    </div>
                    <button v-if="!p.me" type="button" class="x" :title="$t('owner.team.remove')" :aria-label="$t('owner.team.remove')" @click="remove(p)"><Icon name="trash" size="sm" /></button>
                </div>
            </section>
            <aside class="side">
                <form class="card panel" @submit.prevent="add">
                    <div class="sec-h"><h2>{{ $t('owner.team.add') }}</h2></div>
                    <div class="field">
                        <label for="tp-phone">{{ $t('common.phone') }}</label>
                        <input id="tp-phone" v-model="form.phone" class="input" type="tel" inputmode="tel" placeholder="+374 …" required />
                        <small v-if="form.errors.phone || (form.errors as Record<string, string>).booking" class="err">{{ form.errors.phone || (form.errors as Record<string, string>).booking }}</small>
                    </div>
                    <div class="field">
                        <label for="tp-name">{{ $t('owner.team.name') }}</label>
                        <input id="tp-name" v-model="form.name" class="input" maxlength="80" />
                    </div>
                    <div class="seg" role="group" style="margin: 4px 0 12px">
                        <button type="button" :aria-pressed="form.role === 'staff'" @click="form.role = 'staff'">{{ $t('owner.team.role_staff') }}</button>
                        <button type="button" :aria-pressed="form.role === 'owner'" @click="form.role = 'owner'">{{ $t('owner.team.role_owner') }}</button>
                    </div>
                    <button type="submit" class="btn" :disabled="form.processing || !form.phone">{{ $t('owner.team.add_btn') }}</button>
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
.role { flex-shrink: 0; }
.side { display: flex; flex-direction: column; gap: 14px; }
.roles p { margin: 0 0 8px; font-size: 13.5px; color: var(--muted); }
.roles b { color: var(--ink); }
.err { color: var(--hot); }
@media (max-width: 560px) { .tp { flex-wrap: wrap; } .tp .role { order: 3; margin-left: 52px; } }
</style>
