<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { api } from '../api';
import { useI18n } from 'vue-i18n';
import LanguageSwitcher from '../components/LanguageSwitcher.vue';

const { t } = useI18n();
const open = ref(false);
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

const me = ref(null);
const loadMe = async () => { me.value = await api.get('/admin/api/me'); };
onMounted(() => { loadMe(); window.addEventListener('profile-updated', loadMe); });
onUnmounted(() => window.removeEventListener('profile-updated', loadMe));

const allNav = [
    { key: 'dashboard', to: '/admin' },
    { key: 'categories', to: '/admin/categories' },
    { key: 'classes', to: '/admin/classes' },
    { key: 'prices', to: '/admin/prices' },
    { key: 'extras', to: '/admin/extras' },
    { key: 'bookings', to: '/admin/bookings' },
    { key: 'customers', to: '/admin/customers' },
    { key: 'locations', to: '/admin/locations' },
    { key: 'users', to: '/admin/users', adminOnly: true },
    { key: 'visitors', to: '/admin/visitors', ownerOnly: true },
    { key: 'settings', soon: true },
    { key: 'profile', to: '/admin/profile' },
];
// users are managed by admins only
const nav = computed(() => allNav.filter((i) => (!i.adminOnly || me.value?.is_admin) && (!i.ownerOnly || me.value?.is_owner)));
</script>

<template>
    <div class="min-h-screen lg:flex">
        <aside :class="[open ? 'block' : 'hidden', 'w-full shrink-0 bg-brand-950 text-brand-200 lg:block lg:w-60']">
            <div class="px-6 py-5 text-lg font-semibold text-white">Rentacar <span class="text-xs font-normal text-brand-300">{{ t('admin.title').toLowerCase() }}</span></div>
            <nav class="space-y-1 px-3 pb-4">
                <template v-for="item in nav" :key="item.key">
                    <RouterLink v-if="!item.soon" :to="item.to" exact-active-class="bg-brand-800 text-white"
                                class="block rounded-lg px-3 py-2 text-sm hover:bg-brand-900">{{ t('admin.nav.' + item.key) }}</RouterLink>
                    <span v-else class="flex items-center justify-between rounded-lg px-3 py-2 text-sm text-brand-200/40">
                        {{ t('admin.nav.' + item.key) }}<span class="text-[10px] uppercase tracking-wide">{{ t('admin.soon') }}</span>
                    </span>
                </template>
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-3">
                <button class="text-sm text-slate-600 lg:hidden" @click="open = !open">{{ t('common.menu') }}</button>
                <a href="/" class="text-sm text-slate-500 hover:text-slate-900">{{ t('common.viewSite') }}</a>
                <LanguageSwitcher />
                <RouterLink v-if="me" to="/admin/profile" class="text-sm text-slate-600 hover:text-slate-900" :title="t('admin.nav.profile')">{{ me.name }}</RouterLink>
                <form method="POST" action="/logout">
                    <input type="hidden" name="_token" :value="csrf">
                    <button class="text-sm font-medium text-slate-600 hover:text-slate-900">{{ t('common.logout') }}</button>
                </form>
            </header>
            <main class="flex-1 p-6"><RouterView /></main>
        </div>
    </div>
</template>
