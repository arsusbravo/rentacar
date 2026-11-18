<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '../../api';
import { formatCents } from '../../money';
import ConfirmBookingDialog from '../../components/ConfirmBookingDialog.vue';

const { t, locale } = useI18n();

const counts = ref({});
const customers = ref(null);
const waiting = ref([]);

const day = (s) => new Intl.DateTimeFormat(locale.value === 'nl' ? 'nl-NL' : 'en-GB', { day: 'numeric', month: 'short' }).format(new Date(s.replace(' ', 'T')));

const confirming = ref(null);

async function load() {
    const [b, c] = await Promise.all([api.get('/admin/api/bookings?status=unconfirmed'), api.get('/admin/api/customers')]);
    counts.value = b.counts;
    waiting.value = b.data.slice(0, 8);
    customers.value = c.meta.total;
}

async function confirmed() {
    confirming.value = null;
    await load();
}

onMounted(load);
</script>

<template>
    <h1 class="text-2xl font-semibold">{{ t('admin.dashboard.title') }}</h1>

    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <RouterLink :to="{ path: '/admin/bookings', query: { status: 'unconfirmed' } }" class="rounded-xl bg-amber-50 p-5 shadow-sm ring-1 ring-amber-200 hover:bg-amber-100">
            <p class="text-sm text-amber-900">{{ t('admin.dashboard.unconfirmed') }}</p>
            <p class="mt-2 text-3xl font-semibold text-amber-900">{{ counts.unconfirmed ?? '—' }}</p>
        </RouterLink>
        <RouterLink :to="{ path: '/admin/bookings', query: { status: 'confirmed' } }" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">
            <p class="text-sm text-slate-500">{{ t('admin.dashboard.confirmed') }}</p>
            <p class="mt-2 text-3xl font-semibold">{{ counts.confirmed ?? '—' }}</p>
        </RouterLink>
        <RouterLink to="/admin/customers" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">
            <p class="text-sm text-slate-500">{{ t('admin.dashboard.customers') }}</p>
            <p class="mt-2 text-3xl font-semibold">{{ customers ?? '—' }}</p>
        </RouterLink>
    </div>

    <div class="mt-6 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-center justify-between">
            <h2 class="font-medium">{{ t('admin.dashboard.recent') }}</h2>
            <RouterLink to="/admin/bookings" class="text-sm text-brand-700 hover:underline">{{ t('admin.dashboard.viewAll') }}</RouterLink>
        </div>
        <p v-if="!waiting.length" class="mt-6 pb-4 text-center text-sm text-slate-400">{{ t('admin.dashboard.none') }}</p>
        <ul v-else class="mt-3 divide-y divide-slate-100 text-sm">
            <li v-for="b in waiting" :key="b.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                <span><span class="font-mono text-xs text-slate-500">{{ b.reference }}</span> · <span class="font-medium">{{ b.customer.name }}</span> · {{ b.vehicle.name }}</span>
                <span class="flex items-center gap-3 text-slate-500">
                    {{ day(b.pickup.at) }} – {{ day(b.return.at) }} · {{ formatCents(b.total_cents, locale) }}
                    <button class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-700" @click="confirming = b">{{ t('admin.bookings.confirm') }}</button>
                </span>
            </li>
        </ul>
    </div>

    <ConfirmBookingDialog v-if="confirming" :booking="confirming" @close="confirming = null" @confirmed="confirmed" />
</template>
