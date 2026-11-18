<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { api, ApiError } from '../../api';

const { t, locale } = useI18n();
const router = useRouter();

const days = ref(30);
const data = ref(null);
const loading = ref(true);

async function load() {
    loading.value = true;
    try {
        data.value = await api.get(`/admin/api/visitors?days=${days.value}`);
    } catch (e) {
        // owner only: anyone else who typed the address goes back to the dashboard
        if (e instanceof ApiError && e.status === 403) router.replace('/admin'); else throw e;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch(days, load);

const max = computed(() => Math.max(1, ...(data.value?.series ?? []).map((d) => d.visits)));
const dayLabel = (s) => new Intl.DateTimeFormat(locale.value === 'nl' ? 'nl-NL' : 'en-GB', { day: 'numeric', month: 'short' }).format(new Date(`${s}T00:00:00`));
const time = (s) => new Intl.DateTimeFormat(locale.value === 'nl' ? 'nl-NL' : 'en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(s.replace(' ', 'T')));
const share = (list, total) => (total ? Math.round((total / Math.max(1, ...list.map((x) => x.total))) * 100) : 0);
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ t('admin.visitors.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-600">{{ t('admin.visitors.intro') }}</p>
        </div>
        <label class="text-sm text-slate-600">
            {{ t('admin.visitors.period') }}
            <select v-model.number="days" class="ml-2 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm">
                <option v-for="n in [7, 30, 90]" :key="n" :value="n">{{ t('admin.visitors.days', { n }) }}</option>
            </select>
        </label>
    </div>

    <p v-if="loading && !data" class="mt-8 text-sm text-slate-400">…</p>

    <template v-if="data">
        <p v-if="!data.tracking" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ t('admin.visitors.off') }}</p>

        <!-- Totals -->
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" :class="{ 'opacity-60': loading }">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <p class="text-sm text-slate-500">{{ t('admin.visitors.today') }} · {{ t('admin.visitors.visitors').toLowerCase() }}</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums">{{ data.totals.today_visitors }}</p>
            </div>
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <p class="text-sm text-slate-500">{{ t('admin.visitors.today') }} · {{ t('admin.visitors.visits').toLowerCase() }}</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums">{{ data.totals.today_visits }}</p>
            </div>
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <p class="text-sm text-slate-500">{{ t('admin.visitors.visitors') }} · {{ t('admin.visitors.days', { n: data.days }).toLowerCase() }}</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums">{{ data.totals.visitors }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ t('admin.visitors.visitorsHint') }}</p>
            </div>
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <p class="text-sm text-slate-500">{{ t('admin.visitors.visits') }} · {{ t('admin.visitors.days', { n: data.days }).toLowerCase() }}</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums">{{ data.totals.visits }}</p>
            </div>
        </div>

        <!-- Per day -->
        <section class="mt-6 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200" aria-labelledby="chart-title">
            <h2 id="chart-title" class="font-medium">{{ t('admin.visitors.chart') }}</h2>
            <div class="mt-4 flex h-40 items-end gap-px sm:gap-0.5" role="img" :aria-label="t('admin.visitors.chart')">
                <div v-for="d in data.series" :key="d.day" class="group relative flex h-full flex-1 items-end"
                     :title="`${dayLabel(d.day)}: ${d.visitors} ${t('admin.visitors.visitors').toLowerCase()}, ${d.visits} ${t('admin.visitors.visits').toLowerCase()}`">
                    <div class="w-full rounded-t-sm bg-brand-200 transition group-hover:bg-brand-300" :style="{ height: `${(d.visits / max) * 100}%`, minHeight: d.visits ? '2px' : '0' }">
                        <div class="w-full rounded-t-sm bg-brand-700" :style="{ height: d.visits ? `${(d.visitors / d.visits) * 100}%` : 0 }"></div>
                    </div>
                </div>
            </div>
            <div class="mt-2 flex justify-between text-xs text-slate-400">
                <span>{{ dayLabel(data.series[0].day) }}</span><span>{{ dayLabel(data.series[data.series.length - 1].day) }}</span>
            </div>
            <p class="mt-3 flex items-center gap-4 text-xs text-slate-500">
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-brand-700"></span>{{ t('admin.visitors.visitors') }}</span>
                <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-brand-200"></span>{{ t('admin.visitors.visits') }}</span>
            </p>
        </section>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 lg:col-span-2">
                <h2 class="font-medium">{{ t('admin.visitors.pages') }}</h2>
                <p v-if="!data.pages.length" class="mt-4 text-sm text-slate-400">{{ t('admin.visitors.none') }}</p>
                <ul v-else class="mt-3 space-y-2 text-sm">
                    <li v-for="p in data.pages" :key="p.label">
                        <div class="flex justify-between gap-3"><span class="truncate font-mono text-xs">{{ p.label }}</span><span class="tabular-nums text-slate-500">{{ p.total }}</span></div>
                        <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-600" :style="{ width: `${share(data.pages, p.total)}%` }"></div></div>
                    </li>
                </ul>
            </section>

            <div class="space-y-6">
                <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <h2 class="font-medium">{{ t('admin.visitors.referrers') }}</h2>
                    <p v-if="!data.referrers.length" class="mt-3 text-sm text-slate-400">{{ t('admin.visitors.noReferrers') }}</p>
                    <ul v-else class="mt-3 space-y-1.5 text-sm">
                        <li v-for="r in data.referrers" :key="r.label" class="flex justify-between gap-3"><span class="truncate">{{ r.label }}</span><span class="tabular-nums text-slate-500">{{ r.total }}</span></li>
                    </ul>
                </section>
                <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <h2 class="font-medium">{{ t('admin.visitors.devices') }}</h2>
                    <p v-if="!data.devices.length" class="mt-3 text-sm text-slate-400">{{ t('admin.visitors.none') }}</p>
                    <ul v-else class="mt-3 space-y-1.5 text-sm">
                        <li v-for="d in data.devices" :key="d.label" class="flex justify-between gap-3"><span>{{ t('admin.visitors.device.' + d.label) }}</span><span class="tabular-nums text-slate-500">{{ d.total }}</span></li>
                    </ul>
                </section>
            </div>
        </div>

        <!-- Latest -->
        <section class="mt-6 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="font-medium">{{ t('admin.visitors.recent') }}</h2>
            <p v-if="!data.recent.length" class="mt-4 text-sm text-slate-400">{{ t('admin.visitors.none') }}</p>
            <div v-else class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="py-2 pr-4 font-medium">{{ t('admin.visitors.cols.time') }}</th><th class="py-2 pr-4 font-medium">{{ t('admin.visitors.cols.page') }}</th><th class="py-2 pr-4 font-medium">{{ t('admin.visitors.cols.device') }}</th><th class="py-2 pr-4 font-medium">{{ t('admin.visitors.cols.from') }}</th><th class="py-2 font-medium">{{ t('admin.visitors.cols.visitor') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(v, i) in data.recent" :key="i">
                            <td class="whitespace-nowrap py-2 pr-4 text-slate-500">{{ time(v.at) }}</td>
                            <td class="py-2 pr-4 font-mono text-xs">{{ v.path }}</td>
                            <td class="py-2 pr-4">{{ t('admin.visitors.device.' + v.device) }}</td>
                            <td class="py-2 pr-4 text-slate-500">{{ v.referrer ?? t('admin.visitors.direct') }}</td>
                            <td class="py-2 font-mono text-xs text-slate-400">#{{ v.visitor }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Which address is yours -->
        <p class="mt-6 rounded-lg p-3 text-sm" :class="data.your_ip_ignored ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-700'">
            {{ t('admin.visitors.yourIp', { ip: data.your_ip }) }}
            {{ data.your_ip_ignored ? t('admin.visitors.ignored') : t('admin.visitors.notIgnored') }}
            <span class="text-slate-500">{{ t('admin.visitors.retention', { n: data.retention_days }) }}</span>
        </p>
    </template>
</template>
