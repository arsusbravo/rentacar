<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { api, ApiError } from '../../api';

const { t, locale } = useI18n();

const locales = (document.querySelector('meta[name="locales"]')?.content ?? 'nl,en').split(',');

const categories = ref([]);
const loading = ref(true);
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
        categories.value = await api.get('/admin/api/categories');
    } finally {
        loading.value = false;
    }
}

function openForm(category = null) {
    errors.value = {};
    imageFile.value = null;
    removeImage.value = false;
    previewUrl.value = category?.image_url ?? null;
    form.value = category
        ? { ...category, names: { ...category.names } }
        : { id: null, names: Object.fromEntries(locales.map((l) => [l, ''])), sort_order: (categories.value.length + 1) * 10, is_active: true, has_image: false, image_url: null };
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

// Placeholder is shown whenever there is no image (nothing uploaded and nothing saved, or removed).
const shownImage = computed(() => previewUrl.value ?? '/images/category-placeholder.svg');
const hasImage = computed(() => !!previewUrl.value);

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const body = new FormData();
        for (const l of locales) body.append(`names[${l}]`, form.value.names[l] ?? '');
        body.append('sort_order', form.value.sort_order ?? 0);
        body.append('is_active', form.value.is_active ? '1' : '0');
        if (imageFile.value) body.append('image', imageFile.value);
        if (removeImage.value) body.append('remove_image', '1');

        if (form.value.id) {
            body.append('_method', 'PUT'); // PHP cannot read multipart bodies on a real PUT
            await api.post(`/admin/api/categories/${form.value.id}`, body);
        } else {
            await api.post('/admin/api/categories', body);
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

async function remove(category) {
    if (!confirm(t('admin.categories.confirmDelete', { name: category.name }))) return;
    try {
        await api.delete(`/admin/api/categories/${category.id}`);
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.status === 403) alert(t('admin.categories.adminOnly'));
        else if (e instanceof ApiError && e.status === 422) alert(e.message);
        else throw e;
    }
}

watch(locale, load); // names come from the server in the current language
onMounted(load);
</script>

<template>
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ t('admin.categories.title') }}</h1>
        <button class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800" @click="openForm()">
            {{ t('admin.categories.add') }}
        </button>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ t('admin.categories.image') }}</th>
                    <th class="px-4 py-3">{{ t('admin.categories.name') }}</th>
                    <th class="px-4 py-3">{{ t('admin.categories.classes') }}</th>
                    <th class="px-4 py-3">{{ t('admin.branches.status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-if="loading"><td colspan="5" class="px-4 py-8 text-center text-slate-400">…</td></tr>
                <tr v-else-if="!categories.length"><td colspan="5" class="px-4 py-8 text-center text-slate-400">{{ t('admin.categories.none') }}</td></tr>
                <tr v-for="c in categories" :key="c.id">
                    <td class="px-4 py-2"><img :src="c.image_url" alt="" class="aspect-3/2 h-12 rounded-md object-cover ring-1 ring-slate-200"></td>
                    <td class="px-4 py-3 font-medium">{{ c.name }}<span class="block text-xs font-normal text-slate-400">{{ c.slug }}</span></td>
                    <td class="px-4 py-3">{{ c.classes_count }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="c.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                            {{ c.is_active ? t('admin.branches.active') : t('admin.branches.inactive') }}
                        </span>
                    </td>
                    <td class="space-x-3 px-4 py-3 text-right">
                        <button class="text-brand-700 hover:underline" @click="openForm(c)">{{ t('common.edit') }}</button>
                        <button class="text-red-600 hover:underline" @click="remove(c)">{{ t('common.delete') }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-if="form" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="closeForm">
        <form class="max-h-full w-full max-w-lg space-y-4 overflow-y-auto rounded-xl bg-white p-6 shadow-xl" @submit.prevent="save">
            <h2 class="text-lg font-semibold">{{ form.id ? t('admin.categories.edit') : t('admin.categories.add') }}</h2>

            <div v-for="(l, i) in locales" :key="l">
                <label :for="'cat-name-' + l" class="mb-1 block text-sm font-medium">
                    {{ t('admin.categories.nameIn', { lang: l.toUpperCase() }) }}
                    <span v-if="i > 0" class="font-normal text-slate-400">({{ t('admin.categories.optional') }})</span>
                </label>
                <input :id="'cat-name-' + l" v-model="form.names[l]" type="text" :required="i === 0"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200">
                <p v-if="errors[`names.${l}`]" class="mt-1 text-sm text-red-600">{{ errors[`names.${l}`][0] }}</p>
            </div>

            <div>
                <span class="mb-1 block text-sm font-medium">{{ t('admin.categories.image') }}</span>
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
                <p v-if="errors.image" class="mt-1 text-sm text-red-600">{{ errors.image[0] }}</p>
            </div>

            <div class="flex items-end gap-6">
                <div>
                    <label for="cat-order" class="mb-1 block text-sm font-medium">{{ t('admin.categories.order') }}</label>
                    <input id="cat-order" v-model.number="form.sort_order" type="number" min="0"
                           class="w-24 rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200">
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
