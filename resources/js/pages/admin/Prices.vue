<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { api, ApiError } from '../../api';
import { formatMoney } from '../../money';

const { t, locale } = useI18n();

const groups = ref([]);
const meta = ref({ vat_rate: 21, half_day_hours: 6, deposit_margin: 150, deposit_round_to: 50, deposit_surcharge: 250, deposit_leasing_days: 30 });
const loading = ref(true);
const editing = ref(null); // the class being edited (a copy), null = closed
const errors = ref({});
const saving = ref(false);

// time tariffs, shortest to longest, and the per-km price
const columns = ['price_half_day', 'price_day', 'price_week', 'price_month', 'price_year', 'price_km'];
// "Fill in": empty tariffs follow from the day price with these factors (half day, week, month, year)
const factors = { price_half_day: 0.65, price_week: 6, price_month: 20, price_year: 200 };

async function load() {
    loading.value = true;
    try {
        const res = await api.get('/admin/api/rates');
        groups.value = res.data;
        meta.value = { vat_rate: res.vat_rate, half_day_hours: res.half_day_hours, deposit_margin: res.deposit_margin, deposit_round_to: res.deposit_round_to, deposit_surcharge: res.deposit_surcharge, deposit_leasing_days: res.deposit_leasing_days };
    } finally {
        loading.value = false;
    }
}

const classes = computed(() => groups.value.flatMap((g) => g.classes.map((c) => ({ ...c, category: g.label }))));
const pricedCount = (c) => c.combinations.filter((x) => x.priced).length;

function openEditor(c) {
    errors.value = {};
    editing.value = {
        id: c.id,
        name: c.name,
        own_risk: c.own_risk ?? '',
        own_risk_reduced: c.own_risk_reduced ?? '',
        own_risk_reduction_price: c.own_risk_reduction_price ?? '',
        deposit: c.deposit ?? '',
        km_included: c.km_included ?? 250,
        rows: c.combinations.map((x) => ({ ...x, ...Object.fromEntries(columns.map((k) => [k, x[k] ?? ''])) })),
    };
}

function fillFromDayPrice() {
    for (const row of editing.value.rows) {
        const day = Number(row.price_day);
        if (!day) continue;
        for (const [column, factor] of Object.entries(factors)) {
            if (row[column] === '' || row[column] === null) row[column] = Math.round(day * factor * 2) / 2;
        }
    }
}

const blank = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const e = editing.value;
        await api.put(`/admin/api/classes/${e.id}/rates`, {
            rates: e.rows.map((r) => ({ fuel_type: r.fuel_type, transmission: r.transmission, ...Object.fromEntries(columns.map((k) => [k, blank(r[k])])) })),
            own_risk: blank(e.own_risk),
            own_risk_reduced: blank(e.own_risk_reduced),
            own_risk_reduction_price: blank(e.own_risk_reduction_price),
            deposit: blank(e.deposit),
            km_included: blank(e.km_included) ?? 0,
        });
        editing.value = null;
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.errors;
        else throw e;
    } finally {
        saving.value = false;
    }
}

const err = (key) => Object.entries(errors.value).find(([k]) => k === key || k.startsWith(key + '.'))?.[1][0];
const rowErrors = (i) => Object.entries(errors.value).filter(([k]) => k.startsWith(`rates.${i}.`)).map(([, v]) => v[0]);

// Class names and labels come from the server in the current language: reload when it changes.
watch(locale, load);
onMounted(load);

const money = 'w-24 rounded-lg border border-slate-300 px-2 py-1.5 text-sm focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200';
</script>

<template>
    <div>
        <h1 class="text-2xl font-semibold">{{ t('admin.prices.title') }}</h1>
        <p class="mt-1 text-sm text-slate-500">{{ t('admin.prices.vatNote', { rate: meta.vat_rate }) }}</p>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ t('admin.prices.class') }}</th>
                    <th class="px-4 py-3">{{ t('admin.prices.from') }}</th>
                    <th class="px-4 py-3">{{ t('admin.prices.ownRisk') }}</th>
                    <th class="px-4 py-3">{{ t('admin.prices.deposit') }}</th>
                    <th class="px-4 py-3">{{ t('admin.prices.kmIncluded') }}</th>
                    <th class="px-4 py-3">{{ t('admin.prices.status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading"><td colspan="7" class="px-4 py-8 text-center text-slate-400">…</td></tr>
                <template v-for="g in groups" :key="g.category_id">
                    <tr class="bg-slate-50"><td colspan="7" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ g.label }}</td></tr>
                    <tr v-for="c in g.classes" :key="c.id" class="border-t border-slate-100">
                        <td class="px-4 py-3 font-medium">{{ c.name }}<span v-if="!c.is_active" class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500">{{ t('admin.branches.inactive') }}</span></td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <template v-if="c.from_day_price !== null">{{ formatMoney(c.from_day_price, locale) }} <span class="text-slate-500">{{ t('checkout.units.per_day') }}</span></template>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <template v-if="c.own_risk !== null">
                                {{ formatMoney(c.own_risk, locale) }}
                                <span v-if="c.own_risk_reduced !== null && c.own_risk_reduction_price !== null" class="block text-xs text-slate-500">
                                    {{ t('admin.prices.reducible', { amount: formatMoney(c.own_risk_reduced, locale), price: formatMoney(c.own_risk_reduction_price, locale) }) }}
                                </span>
                            </template>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <template v-if="c.deposit !== null">{{ formatMoney(c.deposit, locale) }}</template>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">{{ c.km_included }} km</td>
                        <td class="px-4 py-3">
                            <span v-if="pricedCount(c) === c.combinations.length && c.combinations.length" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ t('admin.prices.complete', { count: c.combinations.length }) }}</span>
                            <span v-else class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">{{ t('admin.prices.incomplete', { done: pricedCount(c), total: c.combinations.length }) }}</span>
                        </td>
                        <td class="px-4 py-3 text-right"><button class="text-brand-700 hover:underline" @click="openEditor(c)">{{ t('admin.prices.edit') }}</button></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="editing = null">
        <form class="max-h-full w-full max-w-5xl space-y-5 overflow-y-auto rounded-xl bg-white p-6 shadow-xl" @submit.prevent="save">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">{{ t('admin.prices.editTitle', { name: editing.name }) }}</h2>
                    <p class="text-xs text-slate-500">{{ t('admin.prices.vatNote', { rate: meta.vat_rate }) }} {{ t('admin.prices.halfDayNote', { hours: meta.half_day_hours }) }}</p>
                </div>
                <button type="button" class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50" @click="fillFromDayPrice">{{ t('admin.prices.fill') }}</button>
            </div>

            <!-- One price set per fuel x transmission -->
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="py-2 pr-3">{{ t('admin.prices.fuel') }}</th>
                            <th class="py-2 pr-3">{{ t('admin.prices.transmission') }}</th>
                            <th v-for="k in columns" :key="k" class="py-2 pr-2">{{ t('admin.prices.columns.' + k) }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template v-for="(row, i) in editing.rows" :key="row.fuel_type + row.transmission">
                            <tr>
                                <td class="py-2 pr-3 font-medium">{{ row.fuel_label }}</td>
                                <td class="py-2 pr-3">{{ row.transmission_label }}</td>
                                <td v-for="k in columns" :key="k" class="py-2 pr-2">
                                    <input v-model="row[k]" type="number" min="0" step="0.01" :class="money" :aria-label="`${row.fuel_label} ${row.transmission_label} – ${t('admin.prices.columns.' + k)}`">
                                </td>
                            </tr>
                            <tr v-if="rowErrors(i).length"><td colspan="8" class="pb-2 text-xs text-red-600">{{ rowErrors(i)[0] }}</td></tr>
                        </template>
                        <tr v-if="!editing.rows.length"><td colspan="8" class="py-4 text-sm text-slate-400">{{ t('admin.prices.noCombinations') }}</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Own risk -->
            <fieldset class="border-t border-slate-200 pt-4">
                <legend class="mb-1 text-sm font-medium">{{ t('admin.prices.ownRisk') }}</legend>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="pr-own" class="mb-1 block text-xs font-medium text-slate-500">{{ t('admin.prices.ownRiskAmount') }} (€)</label>
                        <input id="pr-own" v-model="editing.own_risk" type="number" min="0" step="0.01" :class="money">
                        <p v-if="err('own_risk')" class="mt-1 text-xs text-red-600">{{ err('own_risk') }}</p>
                    </div>
                    <div>
                        <label for="pr-red" class="mb-1 block text-xs font-medium text-slate-500">{{ t('admin.prices.reducedTo') }} (€)</label>
                        <input id="pr-red" v-model="editing.own_risk_reduced" type="number" min="0" step="0.01" :class="money">
                        <p v-if="err('own_risk_reduced')" class="mt-1 text-xs text-red-600">{{ err('own_risk_reduced') }}</p>
                    </div>
                    <div>
                        <label for="pr-redprice" class="mb-1 block text-xs font-medium text-slate-500">{{ t('admin.prices.reductionPrice') }} (€)</label>
                        <input id="pr-redprice" v-model="editing.own_risk_reduction_price" type="number" min="0" step="0.01" :class="money">
                        <p v-if="err('own_risk_reduction_price')" class="mt-1 text-xs text-red-600">{{ err('own_risk_reduction_price') }}</p>
                    </div>
                </div>
                <p class="mt-2 text-xs text-slate-500">{{ t('admin.prices.ownRiskHint') }}</p>
            </fieldset>

            <!-- Kilometres -->
            <fieldset class="border-t border-slate-200 pt-4">
                <legend class="mb-1 text-sm font-medium">{{ t('admin.prices.km') }}</legend>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="pr-km" class="mb-1 block text-xs font-medium text-slate-500">{{ t('admin.prices.kmIncluded') }} (km)</label>
                        <input id="pr-km" v-model="editing.km_included" type="number" min="0" max="99999" step="1" :class="money">
                        <p v-if="err('km_included')" class="mt-1 text-xs text-red-600">{{ err('km_included') }}</p>
                    </div>
                    <p class="text-xs text-slate-500 sm:col-span-2">{{ t('admin.prices.kmHint') }}</p>
                </div>
            </fieldset>

            <!-- Deposit (borg) -->
            <fieldset class="border-t border-slate-200 pt-4">
                <legend class="mb-1 text-sm font-medium">{{ t('admin.prices.deposit') }}</legend>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="pr-deposit" class="mb-1 block text-xs font-medium text-slate-500">{{ t('admin.prices.depositBase') }} (€)</label>
                        <input id="pr-deposit" v-model="editing.deposit" type="number" min="0" step="0.01" :class="money">
                        <p v-if="err('deposit')" class="mt-1 text-xs text-red-600">{{ err('deposit') }}</p>
                    </div>
                    <p class="text-xs text-slate-500 sm:col-span-2">{{ t('admin.prices.depositHint', { margin: meta.deposit_margin, step: meta.deposit_round_to, surcharge: meta.deposit_surcharge, days: meta.deposit_leasing_days }) }}</p>
                </div>
            </fieldset>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100" @click="editing = null">{{ t('common.cancel') }}</button>
                <button :disabled="saving" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800 disabled:opacity-60">{{ t('common.save') }}</button>
            </div>
        </form>
    </div>
</template>
