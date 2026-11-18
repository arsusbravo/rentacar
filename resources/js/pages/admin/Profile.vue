<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { api, ApiError } from '../../api';

const { t } = useI18n();
const minPassword = ref(8);

const me = ref(null);
const name = ref('');
const nameErrors = ref({});
const nameSaved = ref(false);

const pw = ref({ current_password: '', password: '', password_confirmation: '' });
const pwErrors = ref({});
const pwDone = ref(false);
const busy = ref(false);

onMounted(async () => {
    me.value = await api.get('/admin/api/me');
    name.value = me.value.name;
    minPassword.value = me.value.min_password_length;
});

async function saveName() {
    nameErrors.value = {};
    nameSaved.value = false;
    pwDone.value = false;
    try {
        me.value = await api.put('/admin/api/profile', { name: name.value });
        nameSaved.value = true;
        window.dispatchEvent(new CustomEvent('profile-updated'));
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) nameErrors.value = e.errors; else throw e;
    }
}

async function changePassword() {
    busy.value = true;
    pwErrors.value = {};
    pwDone.value = false;
    nameSaved.value = false;
    try {
        await api.put('/admin/api/profile/password', pw.value);
        pw.value = { current_password: '', password: '', password_confirmation: '' };
        pwDone.value = true;
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) pwErrors.value = e.errors; else throw e;
    } finally {
        busy.value = false;
    }
}

const input = 'w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200';
</script>

<template>
    <h1 class="text-2xl font-semibold">{{ t('admin.profile.title') }}</h1>

    <div v-if="me" class="mt-6 grid max-w-4xl gap-6 lg:grid-cols-2">
        <form class="space-y-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200" @submit.prevent="saveName">
            <h2 class="font-semibold">{{ t('admin.profile.account') }}</h2>
            <div>
                <label for="p-name" class="mb-1 block text-sm font-medium">{{ t('admin.profile.name') }}</label>
                <input id="p-name" v-model="name" type="text" required :class="input">
                <p v-if="nameErrors.name" class="mt-1 text-sm text-red-600">{{ nameErrors.name[0] }}</p>
            </div>
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">{{ t('admin.profile.email') }}</dt><dd>{{ me.email }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">{{ t('admin.profile.role') }}</dt><dd>{{ t('admin.users.roles.' + me.role) }}</dd></div>
            </dl>
            <div class="flex items-center gap-3">
                <button class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">{{ t('admin.profile.saveName') }}</button>
                <span v-if="nameSaved" class="text-sm text-emerald-700" role="status">{{ t('admin.profile.saved') }}</span>
            </div>
        </form>

        <form class="space-y-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200" @submit.prevent="changePassword">
            <h2 class="font-semibold">{{ t('admin.profile.passwordTitle') }}</h2>
            <div>
                <label for="p-current" class="mb-1 block text-sm font-medium">{{ t('admin.profile.current') }}</label>
                <input id="p-current" v-model="pw.current_password" type="password" required autocomplete="current-password" :class="input">
                <p v-if="pwErrors.current_password" class="mt-1 text-sm text-red-600">{{ pwErrors.current_password[0] }}</p>
            </div>
            <div>
                <label for="p-new" class="mb-1 block text-sm font-medium">{{ t('admin.profile.new') }}</label>
                <input id="p-new" v-model="pw.password" type="password" required :minlength="minPassword" autocomplete="new-password" :class="input">
                <p class="mt-1 text-xs text-slate-500">{{ t('admin.profile.hint', { n: minPassword }) }}</p>
                <p v-if="pwErrors.password" class="mt-1 text-sm text-red-600">{{ pwErrors.password[0] }}</p>
            </div>
            <div>
                <label for="p-confirm" class="mb-1 block text-sm font-medium">{{ t('admin.profile.confirm') }}</label>
                <input id="p-confirm" v-model="pw.password_confirmation" type="password" required autocomplete="new-password" :class="input">
            </div>
            <div class="flex items-center gap-3">
                <button :disabled="busy" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800 disabled:opacity-60">{{ t('admin.profile.change') }}</button>
                <span v-if="pwDone" class="text-sm text-emerald-700" role="status">{{ t('admin.profile.changed') }}</span>
            </div>
        </form>
    </div>
</template>
