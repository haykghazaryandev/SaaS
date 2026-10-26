<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

// Tournaments: open one (title, date, up to 3 prices), put its link in the Instagram bio; people register with
// name, surname, phone, level and a price option. Here: accept / decline, take the fee, write the places.
interface Opt {
    label: string;
    price: number;
}
interface T {
    id: number;
    title: string;
    date: string;
    time: string;
    description: string | null;
    options: Opt[];
    status: 'open' | 'closed' | 'done';
    link: string;
    entries?: number;
}
interface Entry {
    id: number;
    name: string;
    phone: string;
    tel: string;
    level: number | null;
    option: string;
    price: number;
    status: 'applied' | 'accepted' | 'declined';
    place: number | null;
    paid: { amount: number; method: string } | null;
}
const props = defineProps<{ tournaments: T[]; current: (T & { list: Entry[] }) | null; sport: string }>();
const owner = useOwner();
const canEdit = owner.value.role === 'owner';
const has = (f: string) => (owner.value.features ?? []).includes(f);
const editing = ref<'new' | 'current' | null>(null);
const blank = () => ({ title: '', date: '', time: '11:00', description: '', status: 'open', options: [{ label: '', price: 0 }, { label: '', price: 0 }, { label: '', price: 0 }] as Opt[] });
const form = useForm(blank());
function edit(which: 'new' | 'current') {
    form.clearErrors();
    const c = props.current;
    if (which === 'current' && c) {
        const opts = [...c.options.map((o) => ({ ...o }))];
        while (opts.length < 3) opts.push({ label: '', price: 0 });
        Object.assign(form, { title: c.title, date: c.date, time: c.time, description: c.description ?? '', status: c.status, options: opts });
    } else Object.assign(form, blank());
    editing.value = which;
}
function save() {
    const done = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') form.post(route('owner.tournaments.store'), done);
    else if (props.current) form.put(route('owner.tournaments.update', props.current.id), done);
}
const open = (id: number) => router.get(route('owner.tournaments'), { t: id }, { preserveScroll: true, replace: true });
const entry = (id: number, data: Record<string, string | number | null>) => router.patch(route('owner.tournaments.entry', id), data, { preserveScroll: true });
const copied = ref(false);
async function copy(link: string) {
    try {
        await navigator.clipboard.writeText(link);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1800);
    } catch {
        window.prompt('', link);
    }
}
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.tournaments')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.tournaments') }}</h1>
            </div>
            <div class="phead-r">
                <button v-if="canEdit" type="button" class="btn" data-test="new-tournament" @click="edit('new')"><Icon name="plus" size="sm" />{{ $t('owner.club.new_tournament') }}</button>
            </div>
        </div>

        <div v-if="tournaments.length" class="chips" style="margin-bottom: 12px">
            <button v-for="t in tournaments" :key="t.id" type="button" class="chip" :aria-pressed="current?.id === t.id" @click="open(t.id)">
                {{ t.title }} <span class="faint">{{ t.date.slice(8) }}.{{ t.date.slice(5, 7) }} · {{ t.entries }}</span>
            </button>
        </div>

        <section v-if="editing" class="card panel">
            <div class="sec-h"><h2>{{ editing === 'new' ? $t('owner.club.new_tournament') : form.title }}</h2></div>
            <form class="fgrid" @submit.prevent="save">
                <div class="field" style="grid-column: 1 / -1"><label>{{ $t('owner.club.t_title') }}</label><input v-model="form.title" class="input" required maxlength="100" /></div>
                <div class="field"><label>{{ $t('owner.club.date') }}</label><input v-model="form.date" class="input" type="date" required /></div>
                <div class="field"><label>{{ $t('owner.club.start') }}</label><input v-model="form.time" class="input" type="time" required /></div>
                <div class="field" style="grid-column: 1 / -1"><label>{{ $t('owner.club.t_note') }}</label><textarea v-model="form.description" class="input" rows="2" maxlength="1000" /></div>
                <div class="field" style="grid-column: 1 / -1">
                    <label>{{ $t('owner.club.options') }}</label>
                    <div v-for="(o, i) in form.options" :key="i" class="row" style="gap: 8px; margin-bottom: 6px">
                        <input v-model="o.label" class="input" :placeholder="$t('owner.club.option_ph_' + i)" maxlength="40" style="flex: 1" />
                        <span class="money" style="width: 140px"><input v-model.number="o.price" class="input" type="number" min="0" step="500" /><span>֏</span></span>
                    </div>
                </div>
                <div v-if="editing === 'current'" class="field">
                    <label>{{ $t('common.status') }}</label>
                    <select v-model="form.status" class="input">
                        <option v-for="s in ['open', 'closed', 'done']" :key="s" :value="s">{{ $t('owner.club.t_status_' + s) }}</option>
                    </select>
                </div>
                <div v-for="(e, k) in form.errors" :key="k" class="err" style="grid-column: 1 / -1">{{ e }}</div>
                <div class="row" style="gap: 8px; grid-column: 1 / -1">
                    <button class="btn" :disabled="form.processing">{{ $t('common.save') }}</button>
                    <button type="button" class="btn ghost" @click="editing = null">{{ $t('common.cancel') }}</button>
                </div>
            </form>
        </section>

        <section v-if="current" class="card panel">
            <div class="sec-h" style="align-items: flex-start">
                <div>
                    <h2>{{ current.title }}</h2>
                    <p class="muted" style="margin: 2px 0 0; font-size: 13.5px">
                        {{ current.date.slice(8) }}.{{ current.date.slice(5, 7) }} · {{ current.time }} · {{ $t('owner.club.t_status_' + current.status) }}
                        · {{ current.options.map((o) => `${o.label} ${amd(o.price)}`).join(' / ') }}
                    </p>
                </div>
                <button v-if="canEdit" type="button" class="btn ghost sm" @click="edit('current')"><Icon name="edit" size="sm" /></button>
            </div>
            <div class="linkbox">
                <span class="mono">{{ current.link }}</span>
                <button type="button" class="btn sm" @click="copy(current.link)"><Icon :name="copied ? 'check' : 'share'" size="sm" />{{ copied ? $t('owner.club.copied') : $t('owner.club.copy_link') }}</button>
            </div>
            <p class="faint" style="font-size: 12.5px; margin: 6px 0 12px">{{ $t('owner.club.link_hint') }}</p>

            <div v-for="e in current.list" :key="e.id" :class="['erow', e.status]">
                <div class="w">
                    <b>{{ e.name }}</b>
                    <small><a :href="`tel:${e.tel}`">{{ e.phone }}</a>{{ e.level != null ? ' · ' + $t('owner.club.level_n', { n: e.level }) : '' }} · {{ e.option }} {{ amd(e.price) }}</small>
                </div>
                <span v-if="e.status === 'applied'" class="row" style="gap: 6px">
                    <button type="button" class="btn sm" @click="entry(e.id, { status: 'accepted' })">{{ $t('common.accept') }}</button>
                    <button type="button" class="btn ghost sm" @click="entry(e.id, { status: 'declined' })">{{ $t('common.decline') }}</button>
                </span>
                <span v-else-if="e.status === 'declined'" class="pill">{{ $t('owner.club.declined') }}</span>
                <template v-else>
                    <span v-if="e.paid" class="pill green">{{ amd(e.paid.amount) }} · {{ $t('owner.club.m_' + e.paid.method) }}</span>
                    <span v-else-if="e.price" class="row" style="gap: 6px">
                        <button v-if="has('wallet')" type="button" class="btn sm" @click="entry(e.id, { pay: 'card' })">{{ $t('owner.club.from_card') }}</button>
                        <button type="button" class="btn ghost sm" @click="entry(e.id, { pay: 'cash' })">{{ $t('owner.club.cash') }}</button>
                    </span>
                    <input
                        class="input place"
                        type="number"
                        min="0"
                        :value="e.place ?? ''"
                        :placeholder="$t('owner.club.place_ph')"
                        :aria-label="$t('owner.club.place_ph')"
                        @change="entry(e.id, { place: Number(($event.target as HTMLInputElement).value) || null })"
                    />
                </template>
            </div>
            <p v-if="!current.list.length" class="faint" style="margin: 4px 0">{{ $t('owner.club.no_entries') }}</p>
        </section>
        <p v-else-if="!editing" class="faint">{{ $t('owner.club.no_tournaments') }}</p>
    </OwnerLayout>
</template>

<style scoped>
.linkbox { display: flex; gap: 10px; align-items: center; background: var(--surface-2); border-radius: 12px; padding: 8px 8px 8px 12px; margin-top: 12px; }
.linkbox .mono { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; }
.erow { display: flex; gap: 10px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
.erow.declined { opacity: .5; }
.erow .w { flex: 1; min-width: 180px; display: flex; flex-direction: column; }
.erow small { color: var(--muted); font-size: 12.5px; }
.erow .place { width: 74px; padding: 6px 8px; }
</style>
