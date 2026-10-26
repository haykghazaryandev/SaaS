<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { SPORT_ICON, dateLong } from '@/composables/usePlayer';
import { amd } from '@/composables/useShared';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import { useSportTheme } from '@/lib/sport';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// A tournament's registration page: the link the club puts in its Instagram bio.
// Name, surname, phone, level and one of the price options; no account needed (logged in: filled in).
interface Opt {
    label: string;
    price: number;
}
const props = defineProps<{
    tournament: { code: string; title: string; sport: string; date: string; time: string; description: string | null; options: Opt[]; open: boolean; venue: { slug: string; name: string }; count: number };
    me: { first_name: string; last_name: string; phone: string; registered: boolean } | null;
}>();
useSportTheme(() => props.tournament.sport);
const done = ref(!!props.me?.registered);
const form = useForm({ first_name: props.me?.first_name ?? '', last_name: props.me?.last_name ?? '', phone: props.me?.phone ?? '', level: '' as string | number, option: 0 });
const err = computed(() => (form.errors as Record<string, string>).register);
function send() {
    form.transform((d) => ({ ...d, phone: '+374' + String(d.phone).replace(/\D/g, '').slice(-8), level: d.level === '' ? null : Number(d.level) })).post(route('tournament.register', props.tournament.code), {
        preserveScroll: true,
        onSuccess: () => (done.value = true),
    });
}
</script>

<template>
    <PlayerLayout>
        <Head :title="tournament.title" />
        <div class="pg">
            <div class="card pad tour">
                <span class="kicker">{{ SPORT_ICON[tournament.sport] }} {{ tournament.venue.name }}</span>
                <h1 class="page-title" style="margin: 6px 0">{{ tournament.title }}</h1>
                <p class="muted" style="margin: 0"><Icon name="calendar" size="sm" /> {{ dateLong(tournament.date) }} · {{ tournament.time }} · {{ $t('club.t_registered', { n: tournament.count }) }}</p>
                <p v-if="tournament.description" style="white-space: pre-line">{{ tournament.description }}</p>

                <div v-if="done" class="ok"><Icon name="check" />{{ $t('player.tournament.registered') }}</div>
                <p v-else-if="!tournament.open" class="muted">{{ $t('club.t_closed') }}</p>
                <form v-else class="tf" @submit.prevent="send">
                    <div class="two">
                        <label class="field"><span>{{ $t('club.first_name') }}</span><input v-model="form.first_name" class="input" required minlength="2" maxlength="60" autocomplete="given-name" /></label>
                        <label class="field"><span>{{ $t('club.last_name') }}</span><input v-model="form.last_name" class="input" required minlength="2" maxlength="60" autocomplete="family-name" /></label>
                    </div>
                    <label class="field"
                        ><span>{{ $t('common.phone') }}</span>
                        <div class="row" style="gap: 6px"><span class="input pre">+374</span><input v-model="form.phone" class="input mono" inputmode="tel" required placeholder="91 234 567" /></div
                    ></label>
                    <label class="field"><span>{{ $t('club.level') }}</span><input v-model="form.level" class="input" inputmode="numeric" :placeholder="$t('club.level_ph')" /></label>
                    <div class="field">
                        <span>{{ $t('club.option') }}</span>
                        <div class="opts">
                            <button v-for="(o, i) in tournament.options" :key="i" type="button" class="opt" :aria-pressed="form.option === i" @click="form.option = i">
                                <b>{{ o.label }}</b><small>{{ o.price ? amd(o.price) : $t('club.free') }}</small>
                            </button>
                        </div>
                    </div>
                    <div v-if="err" class="err">{{ err }}</div>
                    <button class="btn block" :disabled="form.processing">{{ $t('club.register') }}</button>
                    <p class="faint" style="font-size: 12.5px; margin: 0">{{ $t('club.register_note') }}</p>
                </form>
                <Link class="btn ghost sm" style="margin-top: 14px" :href="route('places.show', tournament.venue.slug)">{{ tournament.venue.name }}</Link>
            </div>
        </div>
    </PlayerLayout>
</template>

<style scoped>
.pg { max-width: 560px; margin: 0 auto; padding-top: 18px; }
.tour { display: flex; flex-direction: column; gap: 8px; }
.tf { display: flex; flex-direction: column; gap: 12px; margin-top: 8px; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.field > span { display: block; font-size: 12.5px; font-weight: 800; color: var(--muted); margin-bottom: 5px; }
.opts { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; }
.opt { border: 1px solid var(--line-2); background: var(--surface-2); color: var(--ink); border-radius: 14px; padding: 10px; text-align: left; display: flex; flex-direction: column; gap: 2px; }
.opt[aria-pressed="true"] { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
.opt small { color: var(--muted); font-weight: 700; }
.ok { display: flex; gap: 8px; align-items: center; padding: 12px 14px; border-radius: 12px; background: var(--free-soft); color: var(--free); font-weight: 800; }
.pre { flex: none; width: auto; }
</style>
