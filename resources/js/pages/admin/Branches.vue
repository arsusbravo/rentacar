<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { api, ApiError } from '../../api';

const { t } = useI18n();

const branches = ref([]);
const loading = ref(true);
const form = ref(null); // null = closed, otherwise the branch being edited/created
const errors = ref({});
const saving = ref(false);
const imageFile = ref(null);
const removeImage = ref(false);
const previewUrl = ref(null);
const fileInput = ref(null);
const shownImage = computed(() => previewUrl.value ?? '/images/branch-placeholder.svg');
const hasImage = computed(() => !!previewUrl.value);

function onFile(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    if (previewUrl.value?.startsWith('blob:')) URL.revokeObjectURL(previewUrl.value);
    imageFile.value = file;
    previewUrl.value = URL.createObjectURL(file);
    removeImage.value = false;
    e.target.value = '';
}

function clearImage() {
    if (previewUrl.value?.startsWith('blob:')) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
    imageFile.value = null;
    removeImage.value = true;
}

// Monday-Friday 08:00-18:00, weekend closed (ISO weekdays 1-7).
const defaultHours = () => Array.from({ length: 7 }, (_, i) => ({
    weekday: i + 1,
    is_closed: i >= 5,
    opens_at: i < 5 ? '08:00' : '',
    closes_at: i < 5 ? '18:00' : '',
}));

const blank = () => ({ id: null, name: '', city: '', address: '', postal_code: '', phone: '', email: '', is_active: true, opening_hours: defaultHours() });

async function load() {
    loading.value = true;
    try {
        branches.value = await api.get('/admin/api/branches');
    } finally {
        loading.value = false;
    }
}

function openForm(branch = null) {
    errors.value = {};
    imageFile.value = null;
    removeImage.value = false;
    previewUrl.value = branch?.image_url ?? null;
    form.value = branch
        ? { ...branch, opening_hours: branch.opening_hours.map((h) => ({ ...h, opens_at: h.opens_at ?? '', closes_at: h.closes_at ?? '' })) }
        : blank();
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { id, ...payload } = form.value;
        payload.opening_hours = payload.opening_hours.map((h) => (h.is_closed ? { weekday: h.weekday, is_closed: true } : h));
        const saved = id ? await api.put(`/admin/api/branches/${id}`, payload) : await api.post('/admin/api/branches', payload);
        if (imageFile.value) {
            const body = new FormData();
            body.append('image', imageFile.value);
            await api.post(`/admin/api/branches/${saved.id}/image`, body);
        } else if (removeImage.value && id) {
            await api.delete(`/admin/api/branches/${id}/image`);
        }
        form.value = null;
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.errors;
        else throw e;
    } finally {
        saving.value = false;
    }
}

async function remove(branch) {
    if (!confirm(t('admin.branches.confirmDelete', { name: branch.name }))) return;
    try {
        await api.delete(`/admin/api/branches/${branch.id}`);
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.status === 403) alert(t('admin.branches.adminOnly'));
        else throw e;
    }
}

const fields = [
    { key: 'name', type: 'text', required: true },
    { key: 'city', type: 'text', required: true },
    { key: 'address', type: 'text', required: true },
    { key: 'postal_code', type: 'text' },
    { key: 'phone', type: 'tel' },
    { key: 'email', type: 'email' },
];

onMounted(load);
</script>

<template>
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ t('admin.branches.title') }}</h1>
        <button class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800" @click="openForm()">
            {{ t('admin.branches.add') }}
        </button>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ t('admin.branches.image') }}</th>
                    <th class="px-4 py-3">{{ t('admin.branches.fields.name') }}</th>
                    <th class="px-4 py-3">{{ t('admin.branches.fields.city') }}</th>
                    <th class="px-4 py-3">{{ t('admin.branches.fields.address') }}</th>
                    <th class="px-4 py-3">{{ t('admin.branches.status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-if="loading"><td colspan="6" class="px-4 py-8 text-center text-slate-400">…</td></tr>
                <tr v-else-if="!branches.length"><td colspan="6" class="px-4 py-8 text-center text-slate-400">{{ t('admin.branches.none') }}</td></tr>
                <tr v-for="b in branches" :key="b.id">
                    <td class="px-4 py-2"><img :src="b.image_url ?? '/images/branch-placeholder.svg'" alt="" class="aspect-3/2 h-12 rounded-md object-cover ring-1 ring-slate-200"></td>
                    <td class="px-4 py-3 font-medium">{{ b.name }}</td>
                    <td class="px-4 py-3">{{ b.city }}</td>
                    <td class="px-4 py-3">{{ b.address }}<span v-if="b.postal_code">, {{ b.postal_code }}</span></td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="b.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                            {{ b.is_active ? t('admin.branches.active') : t('admin.branches.inactive') }}
                        </span>
                    </td>
                    <td class="space-x-3 px-4 py-3 text-right">
                        <button class="text-brand-700 hover:underline" @click="openForm(b)">{{ t('common.edit') }}</button>
                        <button class="text-red-600 hover:underline" @click="remove(b)">{{ t('common.delete') }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-if="form" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="form = null">
        <form class="max-h-full w-full max-w-lg space-y-4 overflow-y-auto rounded-xl bg-white p-6 shadow-xl" @submit.prevent="save">
            <h2 class="text-lg font-semibold">{{ form.id ? t('admin.branches.edit') : t('admin.branches.add') }}</h2>

            <div v-for="f in fields" :key="f.key">
                <label :for="'b-' + f.key" class="mb-1 block text-sm font-medium">{{ t('admin.branches.fields.' + f.key) }}</label>
                <input :id="'b-' + f.key" v-model="form[f.key]" :type="f.type" :required="f.required"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200">
                <p v-if="errors[f.key]" class="mt-1 text-sm text-red-600">{{ errors[f.key][0] }}</p>
            </div>

            <div>
                <span class="mb-1 block text-sm font-medium">{{ t('admin.branches.image') }}</span>
                <div class="flex items-center gap-4">
                    <img :src="shownImage" alt="" class="aspect-3/2 h-20 rounded-lg object-cover ring-1 ring-slate-200">
                    <div class="space-y-2 text-sm">
                        <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFile">
                        <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 hover:bg-slate-50" @click="fileInput.click()">
                            {{ hasImage ? t('admin.branches.changeImage') : t('admin.branches.chooseImage') }}
                        </button>
                        <button v-if="hasImage" type="button" class="block text-red-600 hover:underline" @click="clearImage">{{ t('admin.branches.removeImage') }}</button>
                        <p class="text-xs text-slate-500">{{ hasImage ? t('admin.branches.imageHint') : t('admin.branches.noImage') + ' · ' + t('admin.branches.imageHint') }}</p>
                    </div>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-slate-300"> {{ t('admin.branches.active') }}
            </label>

            <fieldset class="space-y-2 border-t border-slate-200 pt-4">
                <legend class="text-sm font-medium">{{ t('admin.branches.openingHours') }}</legend>
                <p class="text-xs text-slate-500">{{ t('admin.branches.returnNote') }}</p>
                <div v-for="(h, i) in form.opening_hours" :key="h.weekday" class="grid grid-cols-[6.5rem_1fr_1fr_auto] items-center gap-2 text-sm">
                    <span>{{ t('weekdays.' + (h.weekday - 1)) }}</span>
                    <input v-model="h.opens_at" type="time" :disabled="h.is_closed" :aria-label="t('admin.branches.opens')"
                           class="rounded-lg border border-slate-300 px-2 py-1 disabled:bg-slate-100 disabled:text-transparent">
                    <input v-model="h.closes_at" type="time" :disabled="h.is_closed" :aria-label="t('admin.branches.closes')"
                           class="rounded-lg border border-slate-300 px-2 py-1 disabled:bg-slate-100 disabled:text-transparent">
                    <label class="flex items-center gap-1 text-xs text-slate-600">
                        <input v-model="h.is_closed" type="checkbox" class="size-4 rounded border-slate-300"> {{ t('admin.branches.closed') }}
                    </label>
                    <p v-if="errors[`opening_hours.${i}.opens_at`] || errors[`opening_hours.${i}.closes_at`]" class="col-span-4 text-xs text-red-600">
                        {{ (errors[`opening_hours.${i}.opens_at`] || errors[`opening_hours.${i}.closes_at`])[0] }}
                    </p>
                </div>
            </fieldset>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100" @click="form = null">{{ t('common.cancel') }}</button>
                <button :disabled="saving" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800 disabled:opacity-60">{{ t('common.save') }}</button>
            </div>
        </form>
    </div>
</template>
