<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { api, ApiError } from '../../api';

const { t, locale } = useI18n();

const locales = (document.querySelector('meta[name="locales"]')?.content ?? 'nl,en').split(',');

const classes = ref([]);
const options = ref({ categories: [], fuel_types: [], transmissions: [], license_categories: [] });
const loading = ref(true);
const filterCategory = ref('');
const form = ref(null); // null = closed
const errors = ref({});
const saving = ref(false);
const imageFile = ref(null);
const previewUrl = ref(null);
const removeImage = ref(false);
const fileInput = ref(null);

async function load() {
    loading.value = true;
    try {
        const res = await api.get('/admin/api/classes');
        classes.value = res.data;
        options.value = res.options;
    } finally {
        loading.value = false;
    }
}

const visible = computed(() => (filterCategory.value ? classes.value.filter((c) => c.vehicle_category_id === filterCategory.value) : classes.value));

const blank = () => ({
    id: null,
    vehicle_category_id: filterCategory.value || options.value.categories[0]?.value || '',
    names: Object.fromEntries(locales.map((l) => [l, ''])),
    descriptions: Object.fromEntries(locales.map((l) => [l, ''])),
    example_models: '',
    fuel_types: [options.value.fuel_types[0]?.value].filter(Boolean),
    transmissions: [options.value.transmissions[0]?.value].filter(Boolean),
    license_category: 'B',
    seats: 5, doors: 5, baggage_large: 0, baggage_small: 0, cargo_volume_liters: null, payload_kg: null,
    sort_order: (classes.value.length + 1) * 10,
    is_active: true,
});

function openForm(c = null) {
    errors.value = {};
    imageFile.value = null;
    removeImage.value = false;
    previewUrl.value = c?.has_image ? c.image_url : null;
    form.value = c ? { ...c, names: { ...c.names }, descriptions: { ...c.descriptions }, transmissions: [...c.transmissions], fuel_types: [...c.fuel_types] } : blank();
}

function closeForm() {
    if (previewUrl.value?.startsWith('blob:')) URL.revokeObjectURL(previewUrl.value);
    form.value = null;
}

function onFile(e) {
    const file = e.target.files[0];
    if (!file) return;
    if (previewUrl.value?.startsWith('blob:')) URL.revokeObjectURL(previewUrl.value);
    imageFile.value = file;
    previewUrl.value = URL.createObjectURL(file);
    removeImage.value = false;
}

function clearImage() {
    if (previewUrl.value?.startsWith('blob:')) URL.revokeObjectURL(previewUrl.value);
    imageFile.value = null;
    previewUrl.value = null;
    removeImage.value = true;
    if (fileInput.value) fileInput.value.value = '';
}

const shownImage = computed(() => previewUrl.value ?? '/images/category-placeholder.svg');
const hasImage = computed(() => !!previewUrl.value);

/** The form is sent as multipart because it can carry an image file. */
function toFormData(f) {
    const body = new FormData();
    const add = (key, value) => body.append(key, value ?? ''); // '' arrives as null on the server

    add('vehicle_category_id', f.vehicle_category_id);
    for (const l of locales) { add(`names[${l}]`, f.names[l]); add(`descriptions[${l}]`, f.descriptions[l]); }
    for (const key of ['example_models', 'license_category', 'seats', 'doors', 'baggage_large', 'baggage_small', 'cargo_volume_liters', 'payload_kg', 'sort_order']) add(key, f[key]);
    for (const fuel of f.fuel_types) body.append('fuel_types[]', fuel);
    for (const tr of f.transmissions) body.append('transmissions[]', tr);
    add('is_active', f.is_active ? '1' : '0');
    if (imageFile.value) body.append('image', imageFile.value);
    if (removeImage.value) add('remove_image', '1');

    return body;
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const body = toFormData(form.value);
        if (form.value.id) {
            body.append('_method', 'PUT'); // PHP cannot read multipart bodies on a real PUT
            await api.post(`/admin/api/classes/${form.value.id}`, body);
        } else {
            await api.post('/admin/api/classes', body);
        }
        closeForm();
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.errors;
        else throw e;
    } finally {
        saving.value = false;
    }
}

async function remove(c) {
    if (!confirm(t('admin.classes.confirmDelete', { name: c.name }))) return;
    try {
        await api.delete(`/admin/api/classes/${c.id}`);
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.status === 403) alert(t('admin.classes.adminOnly'));
        else throw e;
    }
}

// First error message for a field (or any of its children, e.g. transmissions.0)
const err = (key) => Object.entries(errors.value).find(([k]) => k === key || k.startsWith(key + '.'))?.[1][0];

// Names and option labels come from the server in the current language: reload when it changes.
watch(locale, load);
onMounted(load);

const input = 'w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200';
const num = (key) => ({ key, label: t('admin.classes.fields.' + key) });
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">{{ t('admin.classes.title') }}</h1>
        <div class="flex items-center gap-3">
            <select v-model="filterCategory" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" :aria-label="t('admin.classes.fields.category')">
                <option value="">{{ t('admin.classes.allCategories') }}</option>
                <option v-for="c in options.categories" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
            <button class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800" @click="openForm()">{{ t('admin.classes.add') }}</button>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ t('admin.classes.fields.image') }}</th>
                    <th class="px-4 py-3">{{ t('admin.classes.fields.name') }}</th>
                    <th class="px-4 py-3">{{ t('admin.classes.fields.category') }}</th>
                    <th class="px-4 py-3">{{ t('admin.classes.fields.seats') }}</th>
                    <th class="px-4 py-3">{{ t('admin.classes.fields.fuel_types') }}</th>
                    <th class="px-4 py-3">{{ t('admin.classes.fields.transmissions') }}</th>
                    <th class="px-4 py-3">{{ t('admin.branches.status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-if="loading"><td colspan="8" class="px-4 py-8 text-center text-slate-400">…</td></tr>
                <tr v-else-if="!visible.length"><td colspan="8" class="px-4 py-8 text-center text-slate-400">{{ t('admin.classes.none') }}</td></tr>
                <tr v-for="c in visible" :key="c.id">
                    <td class="px-4 py-2"><img :src="c.image_url" alt="" loading="lazy" class="aspect-3/2 h-12 rounded-md object-cover ring-1 ring-slate-200"></td>
                    <td class="px-4 py-3 font-medium">{{ c.name }}<span class="block text-xs font-normal text-slate-400">{{ c.example_models }}</span></td>
                    <td class="px-4 py-3">{{ c.category_name }}</td>
                    <td class="px-4 py-3">{{ c.seats }}</td>
                    <td class="px-4 py-3">{{ c.fuel_labels.join(' / ') }}</td>
                    <td class="px-4 py-3">{{ c.transmission_labels.join(' / ') }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="c.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                            {{ c.is_active ? t('admin.branches.active') : t('admin.branches.inactive') }}
                        </span>
                    </td>
                    <td class="space-x-3 whitespace-nowrap px-4 py-3 text-right">
                        <button class="text-brand-700 hover:underline" @click="openForm(c)">{{ t('common.edit') }}</button>
                        <button class="text-red-600 hover:underline" @click="remove(c)">{{ t('common.delete') }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-if="form" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="closeForm">
        <form class="max-h-full w-full max-w-2xl space-y-5 overflow-y-auto rounded-xl bg-white p-6 shadow-xl" @submit.prevent="save">
            <h2 class="text-lg font-semibold">{{ form.id ? t('admin.classes.edit') : t('admin.classes.add') }}</h2>

            <div>
                <span class="mb-1 block text-sm font-medium">{{ t('admin.classes.fields.image') }}</span>
                <div class="flex items-center gap-4">
                    <img :src="shownImage" alt="" class="aspect-3/2 h-20 rounded-lg object-cover ring-1 ring-slate-200">
                    <div class="space-y-2 text-sm">
                        <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFile">
                        <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 hover:bg-slate-50" @click="fileInput.click()">
                            {{ hasImage ? t('admin.categories.changeImage') : t('admin.categories.chooseImage') }}
                        </button>
                        <button v-if="hasImage" type="button" class="block text-red-600 hover:underline" @click="clearImage">{{ t('admin.categories.removeImage') }}</button>
                        <p class="text-xs text-slate-500">{{ hasImage ? t('admin.categories.imageHint') : t('admin.categories.noImage') + ' · ' + t('admin.categories.imageHint') }}</p>
                    </div>
                </div>
                <p v-if="err('image')" class="mt-1 text-sm text-red-600">{{ err('image') }}</p>
            </div>

            <!-- Names and descriptions per language -->
            <div v-for="(l, i) in locales" :key="l" class="space-y-2">
                <div>
                    <label :for="'cl-name-' + l" class="mb-1 block text-sm font-medium">
                        {{ t('admin.classes.fields.nameIn', { lang: l.toUpperCase() }) }}
                        <span v-if="i > 0" class="font-normal text-slate-400">({{ t('admin.classes.optional') }})</span>
                    </label>
                    <input :id="'cl-name-' + l" v-model="form.names[l]" type="text" :required="i === 0" :class="input">
                    <p v-if="err('names.' + l)" class="mt-1 text-sm text-red-600">{{ err('names.' + l) }}</p>
                </div>
                <div>
                    <label :for="'cl-desc-' + l" class="mb-1 block text-xs font-medium text-slate-500">{{ t('admin.classes.fields.descriptionIn', { lang: l.toUpperCase() }) }}</label>
                    <textarea :id="'cl-desc-' + l" v-model="form.descriptions[l]" rows="2" :class="input"></textarea>
                </div>
            </div>

            <div class="grid gap-4 border-t border-slate-200 pt-4 sm:grid-cols-2">
                <div>
                    <label for="cl-cat" class="mb-1 block text-sm font-medium">{{ t('admin.classes.fields.category') }}</label>
                    <select id="cl-cat" v-model="form.vehicle_category_id" required :class="input">
                        <option v-for="c in options.categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </select>
                    <p v-if="err('vehicle_category_id')" class="mt-1 text-sm text-red-600">{{ err('vehicle_category_id') }}</p>
                </div>
                <div>
                    <label for="cl-models" class="mb-1 block text-sm font-medium">{{ t('admin.classes.fields.example_models') }}</label>
                    <input id="cl-models" v-model="form.example_models" type="text" required :placeholder="t('admin.classes.modelsPlaceholder')" :class="input">
                    <p v-if="err('example_models')" class="mt-1 text-sm text-red-600">{{ err('example_models') }}</p>
                </div>
                <div>
                    <label for="cl-lic" class="mb-1 block text-sm font-medium">{{ t('admin.classes.fields.license_category') }}</label>
                    <select id="cl-lic" v-model="form.license_category" required :class="input">
                        <option v-for="o in options.license_categories" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                    <p v-if="err('license_category')" class="mt-1 text-sm text-red-600">{{ err('license_category') }}</p>
                </div>
            </div>

            <fieldset>
                <legend class="mb-1 text-sm font-medium">{{ t('admin.classes.fields.fuel_types') }}</legend>
                <div class="flex flex-wrap gap-x-6 gap-y-1">
                    <label v-for="o in options.fuel_types" :key="o.value" class="flex items-center gap-2 text-sm">
                        <input v-model="form.fuel_types" type="checkbox" :value="o.value" class="size-4 rounded border-slate-300"> {{ o.label }}
                    </label>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ t('admin.classes.fuelHint') }}</p>
                <p v-if="err('fuel_types')" class="mt-1 text-sm text-red-600">{{ err('fuel_types') }}</p>
            </fieldset>

            <fieldset>
                <legend class="mb-1 text-sm font-medium">{{ t('admin.classes.fields.transmissions') }}</legend>
                <div class="flex flex-wrap gap-x-6 gap-y-1">
                    <label v-for="o in options.transmissions" :key="o.value" class="flex items-center gap-2 text-sm">
                        <input v-model="form.transmissions" type="checkbox" :value="o.value" class="size-4 rounded border-slate-300"> {{ o.label }}
                    </label>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ t('admin.classes.transmissionsHint') }}</p>
                <p v-if="err('transmissions')" class="mt-1 text-sm text-red-600">{{ err('transmissions') }}</p>
            </fieldset>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div v-for="f in [num('seats'), num('doors'), num('baggage_large'), num('baggage_small'), num('cargo_volume_liters'), num('payload_kg')]" :key="f.key">
                    <label :for="'cl-' + f.key" class="mb-1 block text-sm font-medium">{{ f.label }}</label>
                    <input :id="'cl-' + f.key" v-model.number="form[f.key]" type="number" min="0" :required="f.key === 'seats'" :class="input">
                    <p v-if="err(f.key)" class="mt-1 text-sm text-red-600">{{ err(f.key) }}</p>
                </div>
            </div>

            <div class="flex items-end gap-6">
                <div>
                    <label for="cl-order" class="mb-1 block text-sm font-medium">{{ t('admin.classes.fields.sort_order') }}</label>
                    <input id="cl-order" v-model.number="form.sort_order" type="number" min="0" class="w-24 rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200">
                </div>
                <label class="flex items-center gap-2 pb-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-slate-300"> {{ t('admin.branches.active') }}
                </label>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100" @click="closeForm">{{ t('common.cancel') }}</button>
                <button :disabled="saving" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800 disabled:opacity-60">{{ t('common.save') }}</button>
            </div>
        </form>
    </div>
</template>
