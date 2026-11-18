<script setup>
import { onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { api, ApiError } from '../../api';
import { formatCents } from '../../money';
import ConfirmBookingDialog from '../../components/ConfirmBookingDialog.vue';

const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();

const tabs = ['', 'unconfirmed', 'confirmed', 'cancelled'];
const status = ref(tabs.includes(route.query.status) ? route.query.status : '');
const customer = route.query.customer ? Number(route.query.customer) : null;
const q = ref('');
const page = ref(1);
const rows = ref([]);
const meta = ref({ page: 1, last_page: 1, total: 0 });
const counts = ref({});
const loading = ref(true);
const detail = ref(null);
const confirming = ref(null);
const busy = ref(false);
const error = ref('');

const statusClass = {
    unconfirmed: 'bg-amber-100 text-amber-800',
    confirmed: 'bg-emerald-100 text-emerald-700',
    cancelled: 'bg-slate-100 text-slate-500',
};
const count = (s) => (s === '' ? Object.values(counts.value).reduce((a, b) => a + Number(b), 0) : Number(counts.value[s] ?? 0));
const dateTime = (s) => new Intl.DateTimeFormat(locale.value === 'nl' ? 'nl-NL' : 'en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(s.replace(' ', 'T')));
const day = (s) => new Intl.DateTimeFormat(locale.value === 'nl' ? 'nl-NL' : 'en-GB', { day: 'numeric', month: 'short' }).format(new Date(s.replace(' ', 'T')));
const money = (c) => formatCents(c, locale.value);

async function load() {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: page.value });
        if (status.value) params.set('status', status.value);
        if (q.value.trim()) params.set('q', q.value.trim());
        if (customer) params.set('customer', customer);
        const res = await api.get(`/admin/api/bookings?${params}`);
        rows.value = res.data;
        meta.value = res.meta;
        counts.value = res.counts;
    } finally {
        loading.value = false;
    }
}

let timer;
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => { page.value = 1; load(); }, 300); });
function setStatus(s) { status.value = s; page.value = 1; router.replace({ query: { ...route.query, status: s || undefined } }); load(); }
function go(p) { page.value = p; load(); }

async function act(booking, action) {
    if (!confirm(t('admin.bookings.confirmCancel', { reference: booking.reference }))) return;
    busy.value = true;
    error.value = '';
    try {
        detail.value = await api.post(`/admin/api/bookings/${booking.id}/${action}`);
        await load();
    } catch (e) {
        if (e instanceof ApiError) error.value = e.message; else throw e;
    } finally {
        busy.value = false;
    }
}

async function confirmed(updated) {
    confirming.value = null;
    if (detail.value?.id === updated.id) detail.value = updated;
    await load();
}

onMounted(load);
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ t('admin.bookings.title') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">{{ t('admin.bookings.intro') }}</p>
        </div>
        <input v-model="q" type="search" :placeholder="t('admin.bookings.search')" :aria-label="t('admin.bookings.search')"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200 sm:w-72">
    </div>

    <div class="mt-5 flex flex-wrap gap-2" role="tablist">
        <button v-for="s in tabs" :key="s" type="button" role="tab" :aria-selected="status === s"
                class="rounded-full px-4 py-1.5 text-sm font-medium ring-1 transition"
                :class="status === s ? 'bg-brand-700 text-white ring-brand-700' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'"
                @click="setStatus(s)">
            {{ t('admin.bookings.tabs.' + (s || 'all')) }} <span class="ml-1 opacity-70">{{ count(s) }}</span>
        </button>
    </div>

    <div class="mt-4 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">{{ t('admin.bookings.cols.reference') }}</th>
                    <th class="px-4 py-3">{{ t('admin.bookings.cols.customer') }}</th>
                    <th class="px-4 py-3">{{ t('admin.bookings.cols.vehicle') }}</th>
                    <th class="px-4 py-3">{{ t('admin.bookings.cols.period') }}</th>
                    <th class="px-4 py-3 text-right">{{ t('admin.bookings.cols.total') }}</th>
                    <th class="px-4 py-3">{{ t('admin.bookings.cols.status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-if="loading"><td colspan="7" class="px-4 py-8 text-center text-slate-400">…</td></tr>
                <tr v-else-if="!rows.length"><td colspan="7" class="px-4 py-8 text-center text-slate-400">{{ t('admin.bookings.none') }}</td></tr>
                <tr v-for="b in rows" :key="b.id" class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-mono text-xs">{{ b.reference }}</td>
                    <td class="px-4 py-3 font-medium">{{ b.customer.name }}</td>
                    <td class="px-4 py-3">{{ b.vehicle.name }}<span class="block text-xs text-slate-500">{{ b.vehicle.fuel_label }} · {{ b.vehicle.transmission_label }}</span></td>
                    <td class="whitespace-nowrap px-4 py-3">{{ day(b.pickup.at) }} – {{ day(b.return.at) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ money(b.total_cents) }}</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass[b.status]">{{ t('admin.bookings.status.' + b.status) }}</span></td>
                    <td class="space-x-3 whitespace-nowrap px-4 py-3 text-right">
                        <button v-if="b.status === 'unconfirmed'" class="rounded-md bg-emerald-600 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-700" @click="confirming = b">{{ t('admin.bookings.confirm') }}</button>
                        <button class="text-brand-700 hover:underline" @click="error = ''; detail = b">{{ t('admin.bookings.view') }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="mt-3 flex items-center justify-between text-sm text-slate-600">
        <span>{{ t('admin.bookings.total', { n: meta.total }) }}</span>
        <div v-if="meta.last_page > 1" class="flex items-center gap-3">
            <button class="rounded-lg px-3 py-1 ring-1 ring-slate-200 enabled:hover:bg-white disabled:opacity-40" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('admin.bookings.prev') }}</button>
            <span>{{ t('admin.bookings.page', { page: meta.page, last: meta.last_page }) }}</span>
            <button class="rounded-lg px-3 py-1 ring-1 ring-slate-200 enabled:hover:bg-white disabled:opacity-40" :disabled="meta.page >= meta.last_page" @click="go(meta.page + 1)">{{ t('admin.bookings.next') }}</button>
        </div>
    </div>

    <ConfirmBookingDialog v-if="confirming" :booking="confirming" @close="confirming = null" @confirmed="confirmed" />

    <div v-if="detail" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="detail = null" @keydown.esc="detail = null">
        <div role="dialog" aria-modal="true" class="max-h-full w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-6 py-4">
                <div>
                    <h2 class="font-mono text-lg font-semibold">{{ detail.reference }}</h2>
                    <p class="text-xs text-slate-500">{{ t('admin.bookings.detail.booked', { date: dateTime(detail.created_at) }) }}
                        <template v-if="detail.confirmed_at"> · {{ t('admin.bookings.detail.confirmedAt', { date: dateTime(detail.confirmed_at) }) }}</template>
                        <template v-if="detail.cancelled_at"> · {{ t('admin.bookings.detail.cancelledAt', { date: dateTime(detail.cancelled_at) }) }}</template>
                    </p>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :class="statusClass[detail.status]">{{ t('admin.bookings.status.' + detail.status) }}</span>
            </div>

            <div class="grid gap-5 px-6 py-5 text-sm sm:grid-cols-2">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('admin.bookings.detail.customer') }}</h3>
                    <p class="mt-1 font-medium">{{ detail.customer.name }}</p>
                    <p class="text-slate-600">{{ detail.customer.email }}<br>{{ detail.customer.phone }}</p>
                    <RouterLink :to="{ path: '/admin/customers', query: { q: detail.customer.name } }" class="text-brand-700 hover:underline">{{ t('admin.bookings.viewCustomer') }}</RouterLink>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('admin.bookings.detail.vehicle') }}</h3>
                    <p class="mt-1 font-medium">{{ detail.vehicle.name }}</p>
                    <p class="text-slate-600">{{ detail.vehicle.example_models }}<br>{{ detail.vehicle.fuel_label }} · {{ detail.vehicle.transmission_label }}</p>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('admin.bookings.detail.pickup') }}</h3>
                    <p class="mt-1">{{ detail.pickup.branch }}<br>{{ dateTime(detail.pickup.at) }}</p>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('admin.bookings.detail.return') }}</h3>
                    <p class="mt-1">{{ detail.return.branch }}<br>{{ dateTime(detail.return.at) }}</p>
                </div>

                <div class="sm:col-span-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('admin.bookings.detail.price') }}</h3>
                    <dl class="mt-2 space-y-1">
                        <div class="flex justify-between"><dt>{{ t('admin.bookings.detail.rental') }}</dt><dd class="tabular-nums">{{ money(detail.rental_cents) }}</dd></div>
                        <div v-if="detail.own_risk_reduced" class="flex justify-between"><dt>{{ t('admin.bookings.detail.ownRisk') }}</dt><dd class="tabular-nums">{{ money(detail.own_risk_reduction_cents) }}</dd></div>
                        <div v-for="(e, i) in detail.extras" :key="i" class="flex justify-between"><dt>{{ e.title }}<span v-if="e.quantity > 1"> × {{ e.quantity }}</span></dt><dd class="tabular-nums">{{ money(e.cents) }}</dd></div>
                        <div v-if="detail.extra_km > 0" class="flex justify-between"><dt>{{ t('admin.bookings.detail.extraKm', { km: detail.extra_km }) }}</dt><dd class="tabular-nums">{{ money(detail.extra_km_cents) }}</dd></div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 text-slate-600"><dt>{{ t('admin.bookings.detail.excl') }}</dt><dd class="tabular-nums">{{ money(detail.subtotal_cents) }}</dd></div>
                        <div class="flex justify-between text-slate-500"><dt>{{ t('admin.bookings.detail.vat') }}</dt><dd class="tabular-nums">{{ money(detail.vat_cents) }}</dd></div>
                        <div class="flex justify-between font-semibold"><dt>{{ t('admin.bookings.detail.incl') }}</dt><dd class="tabular-nums">{{ money(detail.total_cents) }}</dd></div>
                        <div class="flex justify-between border-t border-dashed border-slate-200 pt-2 text-slate-600">
                            <dt>{{ t('admin.bookings.detail.deposit') }}</dt>
                            <dd class="tabular-nums">{{ detail.deposit_cents === null ? t('admin.bookings.detail.noDeposit') : money(detail.deposit_cents) }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <p v-if="error" class="px-6 pb-2 text-sm text-red-600" role="alert">{{ error }}</p>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-6 py-3">
                <p v-if="detail.status === 'unconfirmed'" class="max-w-sm text-xs text-slate-500">{{ t('admin.bookings.confirmHelp') }}</p>
                <span v-else></span>
                <div class="flex gap-2">
                    <button v-if="detail.status !== 'cancelled'" type="button" :disabled="busy" class="rounded-lg px-4 py-2 text-sm font-medium text-red-700 ring-1 ring-red-200 hover:bg-red-50 disabled:opacity-50" @click="act(detail, 'cancel')">{{ t('admin.bookings.cancelBooking') }}</button>
                    <button v-if="detail.status === 'unconfirmed'" type="button" :disabled="busy" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50" @click="confirming = detail">{{ t('admin.bookings.confirm') }}</button>
                    <button type="button" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-white" @click="detail = null">{{ t('admin.bookings.detail.close') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
