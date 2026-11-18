<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { api, ApiError } from '../../api';

const { t } = useI18n();
const router = useRouter();

const users = ref([]);
const loading = ref(true);
const forbidden = ref(false);
const form = ref(null);
const errors = ref({});
const saving = ref(false);
const minPassword = ref(8);

const roles = ['admin', 'employee'];
const blank = () => ({ id: null, name: '', email: '', role: 'employee', password: '', is_self: false });

async function load() {
    loading.value = true;
    try {
        users.value = await api.get('/admin/api/users');
    } catch (e) {
        if (e instanceof ApiError && e.status === 403) forbidden.value = true; else throw e;
    } finally {
        loading.value = false;
    }
}

function openForm(u = null) {
    errors.value = {};
    form.value = u ? { ...u, password: '' } : blank();
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { id, is_self, created_at, ...payload } = form.value;
        id ? await api.put(`/admin/api/users/${id}`, payload) : await api.post('/admin/api/users', payload);
        form.value = null;
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.errors; else throw e;
    } finally {
        saving.value = false;
    }
}

async function remove(u) {
    if (!confirm(t('admin.users.confirmDelete', { name: u.name }))) return;
    try {
        await api.delete(`/admin/api/users/${u.id}`);
        await load();
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) alert(e.message); else throw e;
    }
}

onMounted(async () => {
    const me = await api.get('/admin/api/me');
    // only admins manage users: anyone else goes back to the dashboard (the API refuses them as well)
    if (!me.is_admin) return router.replace('/admin');
    minPassword.value = me.min_password_length;
    await load();
});
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ t('admin.users.title') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">{{ t('admin.users.intro') }}</p>
        </div>
        <button v-if="!forbidden" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800" @click="openForm()">{{ t('admin.users.add') }}</button>
    </div>

    <p v-if="forbidden" class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">{{ t('admin.branches.adminOnly') }}</p>

    <div v-else class="mt-6 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ t('admin.users.cols.name') }}</th>
                    <th class="px-4 py-3">{{ t('admin.users.cols.email') }}</th>
                    <th class="px-4 py-3">{{ t('admin.users.cols.role') }}</th>
                    <th class="px-4 py-3">{{ t('admin.users.cols.created') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-if="loading"><td colspan="5" class="px-4 py-8 text-center text-slate-400">…</td></tr>
                <tr v-else-if="!users.length"><td colspan="5" class="px-4 py-8 text-center text-slate-400">{{ t('admin.users.none') }}</td></tr>
                <tr v-for="u in users" :key="u.id">
                    <td class="px-4 py-3 font-medium">{{ u.name }}<span v-if="u.is_self" class="ml-2 rounded-full bg-brand-50 px-2 py-0.5 text-xs font-normal text-brand-700">{{ t('admin.users.you') }}</span></td>
                    <td class="px-4 py-3">{{ u.email }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="u.role === 'admin' ? 'bg-brand-100 text-brand-800' : 'bg-slate-100 text-slate-600'">{{ t('admin.users.roles.' + u.role) }}</span></td>
                    <td class="px-4 py-3 text-slate-500">{{ u.created_at }}</td>
                    <td class="space-x-3 whitespace-nowrap px-4 py-3 text-right">
                        <button class="text-brand-700 hover:underline" @click="openForm(u)">{{ t('common.edit') }}</button>
                        <button v-if="!u.is_self" class="text-red-600 hover:underline" @click="remove(u)">{{ t('common.delete') }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-if="form" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="form = null">
        <form class="max-h-full w-full max-w-md space-y-4 overflow-y-auto rounded-xl bg-white p-6 shadow-xl" autocomplete="off" @submit.prevent="save">
            <h2 class="text-lg font-semibold">{{ form.id ? t('admin.users.edit') : t('admin.users.add') }}</h2>

            <div>
                <label for="u-name" class="mb-1 block text-sm font-medium">{{ t('admin.users.fields.name') }}</label>
                <input id="u-name" v-model="form.name" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200">
                <p v-if="errors.name" class="mt-1 text-sm text-red-600">{{ errors.name[0] }}</p>
            </div>
            <div>
                <label for="u-email" class="mb-1 block text-sm font-medium">{{ t('admin.users.fields.email') }}</label>
                <input id="u-email" v-model="form.email" type="email" required class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200">
                <p v-if="errors.email" class="mt-1 text-sm text-red-600">{{ errors.email[0] }}</p>
            </div>
            <div>
                <label for="u-role" class="mb-1 block text-sm font-medium">{{ t('admin.users.fields.role') }}</label>
                <select id="u-role" v-model="form.role" :disabled="form.is_self" class="w-full rounded-lg border border-slate-300 px-3 py-2 disabled:bg-slate-100">
                    <option v-for="r in roles" :key="r" :value="r">{{ t('admin.users.roles.' + r) }}</option>
                </select>
                <p v-if="form.is_self" class="mt-1 text-xs text-slate-500">{{ t('admin.users.roleSelf') }}</p>
                <p v-if="errors.role" class="mt-1 text-sm text-red-600">{{ errors.role[0] }}</p>
            </div>
            <div>
                <label for="u-password" class="mb-1 block text-sm font-medium">{{ form.id ? t('admin.users.fields.passwordNew') : t('admin.users.fields.password') }}</label>
                <input id="u-password" v-model="form.password" type="password" :required="!form.id" :minlength="minPassword" autocomplete="new-password"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200">
                <p class="mt-1 text-xs text-slate-500">{{ form.id ? t('admin.users.passwordHint', { n: minPassword }) : t('admin.users.passwordRequired', { n: minPassword }) }}</p>
                <p v-if="errors.password" class="mt-1 text-sm text-red-600">{{ errors.password[0] }}</p>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100" @click="form = null">{{ t('common.cancel') }}</button>
                <button :disabled="saving" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800 disabled:opacity-60">{{ t('common.save') }}</button>
            </div>
        </form>
    </div>
</template>
