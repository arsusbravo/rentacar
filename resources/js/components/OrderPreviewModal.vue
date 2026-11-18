<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatCents } from '../money';
import KmNote from './KmNote.vue';

// Shows the order as it would be booked. DEMO ONLY: nothing was stored or charged.
defineProps({ order: { type: Object, required: true } });
const emit = defineEmits(['close']);

const { t, locale } = useI18n();
const closeButton = ref(null);
const previouslyFocused = document.activeElement;

// "2030-01-02 10:00" / "1990-05-17" -> "02-01-2030 10:00" / "17-05-1990"
const fmt = (s) => `${s.slice(8, 10)}-${s.slice(5, 7)}-${s.slice(0, 4)}${s.length > 10 ? ' ' + s.slice(11) : ''}`;
const unit = (b) => t(`checkout.price.units.${b.unit}`, { count: b.count }, b.count);

function onKey(e) {
    if (e.key === 'Escape') emit('close');
}

onMounted(() => {
    document.addEventListener('keydown', onKey);
    document.body.classList.add('overflow-hidden');
    closeButton.value?.focus();
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey);
    document.body.classList.remove('overflow-hidden');
    previouslyFocused?.focus?.();
});
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-[60] flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" @click.self="emit('close')">
            <div class="flex max-h-full w-full max-w-2xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" role="dialog" aria-modal="true" aria-labelledby="order-title">
                <div class="overflow-y-auto p-6">
                    <div class="rounded-lg bg-amber-50 p-4 text-amber-900 ring-1 ring-amber-200">
                        <h2 id="order-title" class="text-lg font-semibold">{{ t('checkout.order.title') }}</h2>
                        <p class="mt-1 text-sm">{{ t('checkout.order.notice') }}</p>
                    </div>

                    <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('checkout.order.client') }}</dt>
                            <dd class="mt-1">
                                {{ order.client.first_name }} {{ order.client.last_name }}<br>
                                <span class="text-slate-500">{{ t('checkout.order.born', { date: fmt(order.client.date_of_birth) }) }}</span><br>
                                {{ order.client.street }} {{ order.client.house_number }}<br>
                                {{ order.client.postal_code }} {{ order.client.city }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('checkout.vehicle') }}</dt>
                            <dd class="mt-1">
                                {{ order.vehicle.example_models }} <span class="text-slate-400">{{ t('checkout.orSimilar') }}</span><br>
                                <span class="text-slate-500">{{ order.vehicle.category_name }} · {{ order.vehicle.name }}</span><br>
                                {{ order.vehicle.fuel_label }} · {{ order.vehicle.transmission_label }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('checkout.pickup') }}</dt>
                            <dd class="mt-1">{{ order.pickup.branch }}<br>{{ fmt(order.pickup.at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('checkout.return') }}</dt>
                            <dd class="mt-1">{{ order.return.branch }}<br>{{ fmt(order.return.at) }}</dd>
                        </div>
                    </dl>

                    <div class="mt-5 border-t border-slate-100 pt-4 text-sm">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('checkout.price.title') }} · {{ t('checkout.price.days', { count: order.quote.days }, order.quote.days) }}</h3>
                        <ul class="mt-2 space-y-1">
                            <li class="flex justify-between gap-3">
                                <span>{{ t('checkout.price.rental') }}<span class="block text-xs text-slate-500">{{ order.quote.rental.breakdown.map(unit).join(' + ') }}</span></span>
                                <span class="whitespace-nowrap font-medium">{{ formatCents(order.quote.rental.cents, locale) }}</span>
                            </li>
                            <li v-if="order.quote.own_risk.reduction_chosen" class="flex justify-between gap-3">
                                <span>{{ t('checkout.price.ownRiskReduction') }}<span class="block text-xs text-slate-500">{{ t('checkout.price.ownRiskTo', { amount: formatCents(order.quote.own_risk.reduced_cents, locale) }) }}</span></span>
                                <span class="whitespace-nowrap font-medium">{{ formatCents(order.quote.own_risk.reduction_cents, locale) }}</span>
                            </li>
                            <li v-if="order.quote.km.extra > 0" class="flex justify-between gap-3">
                                <span>{{ t('checkout.km.extraLine') }}<span class="block text-xs text-slate-500">{{ order.quote.km.extra }} km × {{ formatCents(order.quote.km.price_cents, locale) }}</span></span>
                                <span class="whitespace-nowrap font-medium">{{ formatCents(order.quote.km.extra_cents, locale) }}</span>
                            </li>
                            <li v-for="x in order.quote.extras" :key="x.id" class="flex justify-between gap-3">
                                <span>{{ x.title }}<span v-if="x.quantity > 1" class="text-slate-500"> × {{ x.quantity }}</span></span>
                                <span class="whitespace-nowrap font-medium">{{ formatCents(x.cents, locale) }}</span>
                            </li>
                        </ul>
                        <dl class="mt-3 space-y-1 border-t border-slate-100 pt-3">
                            <div class="flex justify-between gap-3"><dt class="text-slate-600">{{ t('checkout.price.totalExcl') }}</dt><dd class="font-medium">{{ formatCents(order.quote.subtotal_cents, locale) }}</dd></div>
                            <div class="flex justify-between gap-3 text-xs text-slate-500"><dt>{{ t('checkout.price.vat', { rate: order.quote.vat_rate }) }}</dt><dd>{{ formatCents(order.quote.vat_cents, locale) }}</dd></div>
                            <div class="flex items-baseline justify-between gap-3"><dt class="font-semibold">{{ t('checkout.price.totalIncl') }}</dt><dd class="text-xl font-bold text-brand-800">{{ formatCents(order.quote.total_cents, locale) }}</dd></div>
                        </dl>
                        <p v-if="order.quote.deposit.applicable" class="mt-3 flex items-baseline justify-between gap-3 border-t border-dashed border-slate-200 pt-3">
                            <span class="text-slate-600">{{ t('checkout.price.deposit') }}</span>
                            <strong>{{ formatCents(order.quote.deposit.cents, locale) }}</strong>
                        </p>
                        <p v-else-if="order.quote.deposit.reason === 'leasing'" class="mt-3 border-t border-dashed border-slate-200 pt-3 text-xs text-slate-500">{{ t('checkout.price.noDeposit', { days: order.quote.deposit.leasing_from_days }) }}</p>
                        <KmNote :km="order.quote.km" class="mt-3" />
                    </div>

                    <section class="mt-6 rounded-lg bg-brand-50 p-4 ring-1 ring-brand-100" aria-labelledby="next-steps-title">
                        <h3 id="next-steps-title" class="text-sm font-semibold text-brand-800">{{ t('checkout.order.next.title') }}</h3>
                        <ol class="mt-2 list-decimal space-y-1 ps-5 text-sm text-slate-700">
                            <li>{{ t('checkout.order.next.step1') }}</li>
                            <li>{{ t('checkout.order.next.step2') }}</li>
                            <li>{{ t('checkout.order.next.step3') }}</li>
                        </ol>
                    </section>
                </div>

                <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-6 py-3">
                    <button ref="closeButton" type="button" class="rounded-lg bg-brand-700 px-5 py-2 text-sm font-medium text-white hover:bg-brand-800 focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 focus-visible:outline-hidden" @click="emit('close')">
                        {{ t('checkout.order.close') }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
