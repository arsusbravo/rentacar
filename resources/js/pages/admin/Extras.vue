<script setup>
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { api, ApiError } from '../../api';
import { formatMoney } from '../../money';

const { t, locale } = useI18n();

const locales = (document.querySelector('meta[name="locales"]')?.content ?? 'nl,en').split(',');

const extras = ref([]);
const options = ref({ price_units: [], class_groups: [] });
const loading = ref(true);
const form = ref(null); // null = closed
const errors = ref({});
const saving = ref(false);

async function load() {
    loading.value = true;
    try {
        const res = await api.get('/admin/api/extras');
        extras.value = res.data;
        options.value = res.options;
    } finally {
        loading.value = false;
    }
}

const blank = () => ({
    id: null,
    titles: Object.fromEntries(locales.map((l) => [l, ''])),
    descriptions: Object.fromEntries(locales.map((l) => [l, ''])),
    price: '',
    price_unit: options.value.price_units[0]?.value ?? 'per_day',
    max_quantity: 1,
    classes: [],
    sort_order: (extras.value.length + 1) * 10,
    is_active: true,
});

function openForm(e = null) {
    errors.value = {};
    form.value = e ? { ...e, titles: { ...e.titles }, descriptions: { ...e.descriptions }, classes: [...e.classes] } : blank();
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { id, ...payload } = form.value;
        id ? await api.put(`/admin/api/extras/${id}`, payload) : await api.post('/admin/api/extras', payload);
        form.value = null;
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.errors;
        else throw e;
    } finally {
        saving.value = false;
    }
}

async function remove(e) {
    if (!confirm(t('admin.extras.confirmDelete', { name: e.title }))) return;
    try {
        await api.delete(`/admin/api/extras/${e.id}`);
        await load();
    } catch (err) {
        if (err instanceof ApiError && err.status === 403) alert(t('admin.extras.adminOnly'));
        else throw err;
    }
}

// Coupling to classes, per category: tick a whole category at once.
const groupIds = (g) => g.classes.map((c) => c.value);
const allSelected = (g) => groupIds(g).every((id) => form.value.classes.includes(id));
function toggleGroup(g) {
    const ids = groupIds(g);
    form.value.classes = allSelected(g)
        ? form.value.classes.filter((id) => !ids.includes(id))
        : [...new Set([...form.value.classes, ...ids])];
}

// First error message for a field (or any child of it, e.g. classes.0)
const err = (key) => Object.entries(errors.value).find(([k]) => k === key || k.startsWith(key + '.'))?.[1][0];

// Titles and labels come from the server in the current language: reload when it changes.
watch(locale, load);
onMounted(load);

const input = 'w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200';
</script>

<template>
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ t('admin.extras.title') }}</h1>
        <button class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800" @click="openForm()">{{ t('admin.extras.add') }}</button>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ t('admin.extras.fields.title') }}</th>
                    <th class="px-4 py-3">{{ t('admin.extras.fields.price') }}</th>
                    <th class="px-4 py-3">{{ t('admin.extras.fields.available') }}</th>
                    <th class="px-4 py-3">{{ t('admin.branches.status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-if="loading"><td colspan="5" class="px-4 py-8 text-center text-slate-400">…</td></tr>
                <tr v-else-if="!extras.length"><td colspan="5" class="px-4 py-8 text-center text-slate-400">{{ t('admin.extras.none') }}</td></tr>
                <tr v-for="e in extras" :key="e.id">
                    <td class="px-4 py-3 font-medium">{{ e.title }}<span v-if="e.descriptions[locale] || e.descriptions.nl" class="block max-w-md truncate text-xs font-normal text-slate-400">{{ e.descriptions[locale] || e.descriptions.nl }}</span></td>
                    <td class="whitespace-nowrap px-4 py-3">{{ formatMoney(e.price, locale) }} <span class="text-slate-500">{{ e.price_unit_label }}</span>
                        <span v-if="e.price_unit === 'per_unit' && e.max_quantity > 1" class="block text-xs text-slate-400">{{ t('admin.extras.upTo', { count: e.max_quantity }) }}</span></td>
                    <td class="max-w-xs px-4 py-3">
                        <span v-if="!e.classes.length" class="text-amber-700">{{ t('admin.extras.noClasses') }}</span>
                        <span v-else class="block truncate" :title="e.class_names.join(', ')">{{ t('admin.extras.classCount', { count: e.classes.length }, e.classes.length) }}: {{ e.class_names.join(', ') }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="e.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                            {{ e.is_active ? t('admin.branches.active') : t('admin.branches.inactive') }}
                        </span>
                    </td>
                    <td class="space-x-3 whitespace-nowrap px-4 py-3 text-right">
                        <button class="text-brand-700 hover:underline" @click="openForm(e)">{{ t('common.edit') }}</button>
                        <button class="text-red-600 hover:underline" @click="remove(e)">{{ t('common.delete') }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-if="form" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="form = null">
        <form class="max-h-full w-full max-w-xl space-y-5 overflow-y-auto rounded-xl bg-white p-6 shadow-xl" @submit.prevent="save">
            <h2 class="text-lg font-semibold">{{ form.id ? t('admin.extras.edit') : t('admin.extras.add') }}</h2>

            <!-- Title and description per language -->
            <div v-for="(l, i) in locales" :key="l" class="space-y-2">
                <div>
                    <label :for="'ex-title-' + l" class="mb-1 block text-sm font-medium">
                        {{ t('admin.extras.fields.titleIn', { lang: l.toUpperCase() }) }}
                        <span v-if="i > 0" class="font-normal text-slate-400">({{ t('admin.extras.optional') }})</span>
                    </label>
                    <input :id="'ex-title-' + l" v-model="form.titles[l]" type="text" :required="i === 0" :class="input">
                    <p v-if="err('titles.' + l)" class="mt-1 text-sm text-red-600">{{ err('titles.' + l) }}</p>
                </div>
                <div>
                    <label :for="'ex-desc-' + l" class="mb-1 block text-xs font-medium text-slate-500">{{ t('admin.extras.fields.descriptionIn', { lang: l.toUpperCase() }) }}</label>
                    <textarea :id="'ex-desc-' + l" v-model="form.descriptions[l]" rows="2" :class="input"></textarea>
                </div>
            </div>

            <!-- Price -->
            <div class="grid gap-4 border-t border-slate-200 pt-4 sm:grid-cols-2">
                <div>
                    <label for="ex-price" class="mb-1 block text-sm font-medium">{{ t('admin.extras.fields.price') }} (€)</label>
                    <input id="ex-price" v-model="form.price" type="number" min="0" step="0.01" required :class="input">
                    <p v-if="err('price')" class="mt-1 text-sm text-red-600">{{ err('price') }}</p>
                </div>
                <div>
                    <label for="ex-unit" class="mb-1 block text-sm font-medium">{{ t('admin.extras.fields.price_unit') }}</label>
                    <select id="ex-unit" v-model="form.price_unit" required :class="input">
                        <option v-for="o in options.price_units" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                    <p v-if="err('price_unit')" class="mt-1 text-sm text-red-600">{{ err('price_unit') }}</p>
                </div>
                <div v-if="form.price_unit === 'per_unit'">
                    <label for="ex-max" class="mb-1 block text-sm font-medium">{{ t('admin.extras.fields.max_quantity') }}</label>
                    <input id="ex-max" v-model.number="form.max_quantity" type="number" min="1" max="20" :class="input">
                    <p class="mt-1 text-xs text-slate-500">{{ t('admin.extras.maxHint') }}</p>
                    <p v-if="err('max_quantity')" class="mt-1 text-sm text-red-600">{{ err('max_quantity') }}</p>
                </div>
            </div>
            <p class="-mt-2 text-xs text-slate-500">{{ t('admin.extras.unitHint.' + form.price_unit) }}</p>

            <!-- The vehicle classes this extra is coupled to -->
            <fieldset>
                <legend class="mb-1 text-sm font-medium">{{ t('admin.extras.fields.available') }}</legend>
                <p class="mb-2 text-xs text-slate-500">{{ t('admin.extras.classesHint') }}</p>

                <div class="space-y-3 rounded-lg border border-slate-200 p-3">
                    <div v-for="g in options.class_groups" :key="g.category_id">
                        <label class="flex items-center gap-2 text-sm font-semibold">
                            <input type="checkbox" class="size-4 rounded border-slate-300" :checked="allSelected(g)" @change="toggleGroup(g)"> {{ g.label }}
                        </label>
                        <div class="mt-1 flex flex-wrap gap-x-5 gap-y-1 pl-6">
                            <label v-for="c in g.classes" :key="c.value" class="flex items-center gap-2 text-sm">
                                <input v-model="form.classes" type="checkbox" :value="c.value" class="size-4 rounded border-slate-300"> {{ c.label }}
                            </label>
                        </div>
                    </div>
                    <p v-if="!options.class_groups.length" class="text-sm text-slate-400">{{ t('admin.extras.noClassesExist') }}</p>
                </div>
                <p v-if="!form.classes.length" class="mt-1 text-xs text-amber-700">{{ t('admin.extras.noneSelected') }}</p>
                <p v-if="err('classes')" class="mt-1 text-sm text-red-600">{{ err('classes') }}</p>
            </fieldset>

            <div class="flex items-end gap-6">
                <div>
                    <label for="ex-order" class="mb-1 block text-sm font-medium">{{ t('admin.extras.fields.sort_order') }}</label>
                    <input id="ex-order" v-model.number="form.sort_order" type="number" min="0" class="w-24 rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200">
                </div>
                <label class="flex items-center gap-2 pb-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-slate-300"> {{ t('admin.branches.active') }}
                </label>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100" @click="form = null">{{ t('common.cancel') }}</button>
                <button :disabled="saving" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800 disabled:opacity-60">{{ t('common.save') }}</button>
            </div>
        </form>
    </div>
</template>
