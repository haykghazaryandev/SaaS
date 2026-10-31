<script setup lang="ts">
import Pager from '@/components/Pager.vue';
import Icon from '@/components/Icon.vue';
import { ask } from '@/composables/useConfirm';
import { useMay, useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { trans } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

// Shop: products with a photo, price, discount and "in stock". Players see them on the place page.
// Sales to a player go through their card (scan → shop); "Sell" here is a cash sale to anyone.
interface Product {
    id: number;
    name: string;
    description: string | null;
    price: number;
    final: number;
    discount_pct: number;
    in_stock: boolean;
    active: boolean;
    photo: string | null;
}
const props = defineProps<{
    products: Product[];
    sales: { data: { id: number; what: string; who: string | null; amount: number | null; method: string | null; at: string }[]; page: number; pages: number; total: number };
    salesFilter: { period: string; product: number | null };
}>();
// the sales list: a new filter starts on its first page
function filterSales(change: Record<string, string | number | null>) {
    const q = { period: props.salesFilter.period, product: props.salesFilter.product, ...change };
    router.get(route('owner.shop'), Object.fromEntries(Object.entries(q).filter(([k, v]) => v && !(k === 'period' && v === 'all'))), { preserveScroll: true, preserveState: true, replace: true, only: ['sales', 'salesFilter'] });
}
const owner = useOwner();
// the owner, or staff the owner let change the items (Settings → Menus)
const canEdit = useMay()('shop', 'edit');
const editing = ref<number | 'new' | null>(null);
const preview = ref<string | null>(null);
const form = useForm({ name: '', description: '', price: 0, discount_pct: 0, in_stock: true, active: true, photo: null as File | null });
function edit(p?: Product) {
    form.clearErrors();
    Object.assign(form, p ? { name: p.name, description: p.description ?? '', price: p.price, discount_pct: p.discount_pct, in_stock: p.in_stock, active: p.active, photo: null }
        : { name: '', description: '', price: 0, discount_pct: 0, in_stock: true, active: true, photo: null });
    preview.value = p?.photo ?? null;
    editing.value = p ? p.id : 'new';
}
function pick(e: Event) {
    const f = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.photo = f;
    preview.value = f ? URL.createObjectURL(f) : preview.value;
}
function save() {
    const url = editing.value === 'new' ? route('owner.shop.store') : route('owner.shop.update', editing.value as number);
    form.transform((d) => ({ ...d, in_stock: d.in_stock ? 1 : 0, active: d.active ? 1 : 0 })).post(url, { preserveScroll: true, forceFormData: true, onSuccess: () => (editing.value = null) });
}
const qty = reactive<Record<number, number>>({});
// a sale at the desk, in cash: ask with the sum
async function sell(id: number) {
    const p = props.products.find((x) => x.id === id);
    const n = qty[id] || 1;
    if (
        p &&
        (await ask({
            title: trans('owner.club.ask_sell', { n, name: p.name, a: amd(n * p.final) }),
            text: trans('owner.club.ask_cash_hands'),
            ok: trans('owner.club.ask_take'),
        }))
    )
        router.post(route('owner.shop.sell', id), { qty: n }, { preserveScroll: true, onSuccess: () => (qty[id] = 1) });
}
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.shop')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.shop') }}</h1>
            </div>
            <div class="phead-r">
                <button v-if="canEdit" type="button" class="btn" data-test="new-product" @click="edit()"><Icon name="plus" size="sm" />{{ $t('owner.club.new_product') }}</button>
            </div>
        </div>

        <section v-if="editing" class="card panel">
            <div class="sec-h"><h2>{{ editing === 'new' ? $t('owner.club.new_product') : form.name }}</h2></div>
            <form class="pform" @submit.prevent="save">
                <label class="ph">
                    <img v-if="preview" :src="preview" alt="" />
                    <span v-else><Icon name="plus" />{{ $t('owner.club.photo') }}</span>
                    <input type="file" accept="image/*" hidden @change="pick" />
                </label>
                <div class="fgrid" style="flex: 1">
                    <div class="field" style="grid-column: 1 / -1"><label>{{ $t('owner.club.p_name') }}</label><input v-model="form.name" class="input" required maxlength="80" /></div>
                    <div class="field" style="grid-column: 1 / -1"><label>{{ $t('owner.club.p_desc') }}</label><input v-model="form.description" class="input" maxlength="300" /></div>
                    <div class="field"><label>{{ $t('owner.club.price') }}</label><input v-model.number="form.price" class="input" type="number" min="0" step="100" required /></div>
                    <div class="field"><label>{{ $t('owner.club.discount') }}</label><input v-model.number="form.discount_pct" class="input" type="number" min="0" max="100" /></div>
                    <label class="switch"><input v-model="form.in_stock" type="checkbox" />{{ $t('owner.club.in_stock') }}</label>
                    <label class="switch"><input v-model="form.active" type="checkbox" />{{ $t('owner.club.shown') }}</label>
                    <div v-for="(e, k) in form.errors" :key="k" class="err" style="grid-column: 1 / -1">{{ e }}</div>
                    <div class="row" style="gap: 8px; grid-column: 1 / -1">
                        <button class="btn" :disabled="form.processing">{{ $t('common.save') }}</button>
                        <button type="button" class="btn ghost" @click="editing = null">{{ $t('common.cancel') }}</button>
                    </div>
                </div>
            </form>
        </section>

        <div class="pgrid">
            <article v-for="p in products" :key="p.id" :class="['card', 'prod', { off: !p.active }]">
                <div class="img">
                    <img v-if="p.photo" :src="p.photo" alt="" /><Icon v-else name="cube" size="lg" />
                    <!-- edit: a round button on the photo (the row below keeps quantity + sell, whatever the language) -->
                    <button v-if="canEdit" type="button" class="pedit" :aria-label="$t('common.edit')" :title="$t('common.edit')" @click="edit(p)"><Icon name="edit" size="sm" /></button>
                </div>
                <div class="body">
                    <b>{{ p.name }}</b>
                    <span class="pr"
                        ><s v-if="p.discount_pct">{{ amd(p.price) }}</s> {{ amd(p.final) }}<span v-if="p.discount_pct" class="pill warn">−{{ p.discount_pct }}%</span></span
                    >
                    <span v-if="!p.in_stock" class="pill">{{ $t('owner.club.out_of_stock') }}</span>
                    <div class="pact">
                        <input v-model.number="qty[p.id]" class="input qty" type="number" min="1" placeholder="1" :aria-label="$t('owner.club.qty')" />
                        <button type="button" class="btn sm" :disabled="!p.in_stock" :title="$t('owner.club.sell_cash')" @click="sell(p.id)">{{ $t('owner.club.sell_cash') }}</button>
                    </div>
                </div>
            </article>
        </div>
        <p v-if="!products.length" class="faint">{{ $t('owner.club.no_products') }}</p>

        <section class="card panel" data-test="sales">
            <div class="sec-h"><h2>{{ $t('owner.club.sales') }}</h2></div>
            <div class="sfil">
                <div class="seg sm" role="group">
                    <button v-for="p in ['all', 'today', 'week', 'month']" :key="p" type="button" :aria-pressed="salesFilter.period === p" @click="filterSales({ period: p })">
                        {{ $t(p === 'all' ? 'common.all' : 'owner.club.period_' + p) }}
                    </button>
                </div>
                <select class="input sm" :value="salesFilter.product ?? ''" :aria-label="$t('owner.money.product')" @change="filterSales({ product: Number(($event.target as HTMLSelectElement).value) || null })">
                    <option value="">{{ $t('owner.money.all_products') }}</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
            </div>
            <div v-for="s in sales.data" :key="s.id" class="srow">
                <span class="mono faint">{{ s.at }}</span><span class="w">{{ s.what }}<small v-if="s.who"> · {{ s.who }}</small></span
                ><span class="mono">{{ amd(s.amount ?? 0) }} · {{ $t('owner.club.m_' + s.method) }}</span>
            </div>
            <p v-if="!sales.data.length" class="faint" style="margin: 4px 0">{{ $t('owner.money.no_sales') }}</p>
            <Pager :page="sales.page" :pages="sales.pages" :total="sales.total" :only="['sales', 'salesFilter']" />
        </section>
    </OwnerLayout>
</template>

<style scoped>
.sfil { display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; margin-bottom: 6px; }
.sfil select { max-width: 220px; }
.pform { display: flex; gap: 16px; flex-wrap: wrap; }
.ph { width: 140px; height: 140px; border-radius: 16px; border: 1px dashed var(--line-2); display: grid; place-items: center; overflow: hidden; cursor: pointer; color: var(--muted); font-weight: 700; font-size: 13px; text-align: center; }
.ph img { width: 100%; height: 100%; object-fit: cover; }
.ph span { display: flex; flex-direction: column; align-items: center; gap: 6px; }
.pgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; margin-bottom: 14px; }
.prod { padding: 0; overflow: hidden; }
.prod.off { opacity: .5; }
.prod .img { position: relative; }
.pedit { position: absolute; top: 8px; right: 8px; width: 34px; height: 34px; border-radius: 50%; border: 1px solid rgba(255, 255, 255, 0.25); background: rgba(10, 13, 11, 0.7); color: #fff; display: grid; place-items: center; cursor: pointer; backdrop-filter: blur(6px); }
.pedit:hover { border-color: var(--accent); color: var(--accent); }
.pact { display: grid; grid-template-columns: 64px minmax(0, 1fr); gap: 6px; margin-top: 8px; }
.pact .btn { min-width: 0; white-space: normal; line-height: 1.15; padding-inline: 10px; }
.pact .qty { width: 100%; min-width: 0; }
.prod .img { aspect-ratio: 4 / 3; background: var(--surface-2); display: grid; place-items: center; color: var(--faint); }
.prod .img img { width: 100%; height: 100%; object-fit: cover; }
.prod .body { padding: 12px; display: flex; flex-direction: column; gap: 4px; }
.prod .pr { font-family: var(--mono); font-weight: 700; display: flex; gap: 6px; align-items: center; }
.prod .pr s { color: var(--faint); font-weight: 400; }
.qty { width: 62px; padding: 6px 8px; }
.srow { display: flex; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--line); font-size: 13.5px; }
.srow .w { flex: 1; }
.srow small { color: var(--muted); }
</style>
