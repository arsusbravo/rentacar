<script setup>
import { onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { api, ApiError } from '../../api';

const { t } = useI18n();
const route = useRoute();

const q = ref(typeof route.query.q === 'string' ? route.query.q : '');
const page = ref(1);
const rows = ref([]);
const meta = ref({ page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const form = ref(null);
const errors = ref({});
const saving = ref(false);

const fields = [
    { key: 'first_name', type: 'text', required: true },
    { key: 'last_name', type: 'text', required: true },
    { key: 'date_of_birth', type: 'date' },
    { key: 'email', type: 'email' },
    { key: 'phone', type: 'tel' },
    { key: 'street', type: 'text' },
    { key: 'house_number', type: 'text' },
    { key: 'postal_code', type: 'text' },
    { key: 'city', type: 'text' },
];
const blank = () => ({ id: null, first_name: '', last_name: '', date_of_birth: '', email: '', phone: '', street: '', house_number: '', postal_code: '', city: '', notes: '' });

async function load() {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: page.value });
        if (q.value.trim()) params.set('q', q.value.trim());
        const res = await api.get(`/admin/api/customers?${params}`);
        rows.value = res.data;
        meta.value = res.meta;
    } finally {
        loading.value = false;
    }
}

let timer;
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => { page.value = 1; load(); }, 300); });
function go(p) { page.value = p; load(); }

function openForm(c = null) {
    errors.value = {};
    form.value = c ? Object.fromEntries(Object.entries(c).map(([k, v]) => [k, v ?? ''])) : blank();
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { id, ...payload } = form.value;
        for (const k of Object.keys(payload)) if (payload[k] === '') payload[k] = null;
        id ? await api.put(`/admin/api/customers/${id}`, payload) : await api.post('/admin/api/customers', payload);
        form.value = null;
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.errors;
        else throw e;
    } finally {
        saving.value = false;
    }
}

async function remove(c) {
    if (!confirm(t('admin.customers.confirmDelete', { name: c.full_name }))) return;
    try {
        await api.delete(`/admin/api/customers/${c.id}`);
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.status === 403) alert(t('admin.customers.adminOnly'));
        else if (e instanceof ApiError && e.status === 422) alert(t('admin.customers.hasBookings'));
        else throw e;
    }
}

onMounted(load);
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">{{ t('admin.customers.title') }}</h1>
        <div class="flex w-full gap-3 sm:w-auto">
            <input v-model="q" type="search" :placeholder="t('admin.customers.search')" :aria-label="t('admin.customers.search')"
                   class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200 sm:w-72">
            <button class="shrink-0 rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800" @click="openForm()">{{ t('admin.customers.add') }}</button>
        </div>
    </div>

    <div class="mt-5 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ t('admin.customers.cols.name') }}</th>
                    <th class="px-4 py-3">{{ t('admin.customers.cols.email') }}</th>
                    <th class="px-4 py-3">{{ t('admin.customers.cols.phone') }}</th>
                    <th class="px-4 py-3">{{ t('admin.customers.cols.city') }}</th>
                    <th class="px-4 py-3 text-right">{{ t('admin.customers.cols.bookings') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-if="loading"><td colspan="6" class="px-4 py-8 text-center text-slate-400">…</td></tr>
                <tr v-else-if="!rows.length"><td colspan="6" class="px-4 py-8 text-center text-slate-400">{{ t('admin.customers.none') }}</td></tr>
                <tr v-for="c in rows" :key="c.id" class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium">{{ c.full_name }}</td>
                    <td class="px-4 py-3">{{ c.email }}</td>
                    <td class="whitespace-nowrap px-4 py-3">{{ c.phone }}</td>
                    <td class="px-4 py-3">{{ c.city }}</td>
                    <td class="px-4 py-3 text-right">
                        <RouterLink v-if="c.bookings_count" :to="{ path: '/admin/bookings', query: { customer: c.id } }" class="text-brand-700 hover:underline">{{ c.bookings_count }}</RouterLink>
                        <span v-else class="text-slate-400">0</span>
                    </td>
                    <td class="space-x-3 whitespace-nowrap px-4 py-3 text-right">
                        <button class="text-brand-700 hover:underline" @click="openForm(c)">{{ t('common.edit') }}</button>
                        <button class="text-red-600 hover:underline" @click="remove(c)">{{ t('common.delete') }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="mt-3 flex items-center justify-between text-sm text-slate-600">
        <span>{{ t('admin.customers.total', { n: meta.total }) }}</span>
        <div v-if="meta.last_page > 1" class="flex items-center gap-3">
            <button class="rounded-lg px-3 py-1 ring-1 ring-slate-200 enabled:hover:bg-white disabled:opacity-40" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('admin.bookings.prev') }}</button>
            <span>{{ t('admin.bookings.page', { page: meta.page, last: meta.last_page }) }}</span>
            <button class="rounded-lg px-3 py-1 ring-1 ring-slate-200 enabled:hover:bg-white disabled:opacity-40" :disabled="meta.page >= meta.last_page" @click="go(meta.page + 1)">{{ t('admin.bookings.next') }}</button>
        </div>
    </div>

    <div v-if="form" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="form = null">
        <form class="max-h-full w-full max-w-lg space-y-4 overflow-y-auto rounded-xl bg-white p-6 shadow-xl" @submit.prevent="save">
            <h2 class="text-lg font-semibold">{{ form.id ? t('admin.customers.edit') : t('admin.customers.add') }}</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div v-for="f in fields" :key="f.key">
                    <label :for="'c-' + f.key" class="mb-1 block text-sm font-medium">{{ t('admin.customers.fields.' + f.key) }}</label>
                    <input :id="'c-' + f.key" v-model="form[f.key]" :type="f.type" :required="f.required"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200">
                    <p v-if="errors[f.key]" class="mt-1 text-sm text-red-600">{{ errors[f.key][0] }}</p>
                </div>
            </div>
            <div>
                <label for="c-notes" class="mb-1 block text-sm font-medium">{{ t('admin.customers.fields.notes') }}</label>
                <textarea id="c-notes" v-model="form.notes" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200"></textarea>
                <p v-if="errors.notes" class="mt-1 text-sm text-red-600">{{ errors.notes[0] }}</p>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50" @click="form = null">{{ t('common.cancel') }}</button>
                <button type="submit" :disabled="saving" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800 disabled:opacity-50">{{ t('common.save') }}</button>
            </div>
        </form>
    </div>
</template>
