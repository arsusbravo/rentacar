<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ClientForm from '../components/ClientForm.vue';
import ExtrasPicker from '../components/ExtrasPicker.vue';
import KmNote from '../components/KmNote.vue';
import OrderPreviewModal from '../components/OrderPreviewModal.vue';
import RentalDetailsForm from '../components/RentalDetailsForm.vue';
import { api, ApiError } from '../api';
import { formatCents, formatMoney } from '../money';

const props = defineProps({
    branches: { type: Array, default: () => [] },
    initial: { type: Object, default: () => ({}) },
    // The selected vehicle class: { id, name, example_models, image_url, category_name, fuel_types, transmissions, selected_fuel, selected_transmission }
    vehicleClass: { type: Object, required: true },
    extras: { type: Array, default: () => [] }, // extras offered for this vehicle's category
    vehiclesUrl: { type: String, default: '/vehicles' }, // back to the vehicle choice, keeping the search
    minAge: { type: Number, default: 18 },                // minimum driver age on the pick-up date
});

const { t, locale } = useI18n();
const period = ref(null); // the accepted pick-up/return (null until the form validated)
const choiceMissing = ref(false);

// A class offering several fuels / transmissions makes the client choose; with one it is fixed.
const fuel = ref(props.vehicleClass.selected_fuel);
const transmission = ref(props.vehicleClass.selected_transmission);
const label = (options, value) => options.find((o) => o.value === value)?.label;

// Manual (the default) is preselected; every other transmission is a checkbox that replaces it.
const alternativeTransmissions = props.vehicleClass.transmissions.filter((o) => o.value !== props.vehicleClass.default_transmission);
function toggleTransmission(value, on) {
    transmission.value = on ? value : props.vehicleClass.default_transmission; // ticking one unticks the others
}

// Selected extras: { [extraId]: quantity }, and whether the own risk is lowered.
const selectedExtras = ref({});
const reduceOwnRisk = ref(false);

// Extra kilometres (optional): a flat number of km is included in the price; more can be booked in advance.
const extraKm = ref('');                  // what is typed ('' = none)
const kmUnavailable = ref(false);         // this fuel/transmission has no per-km price: no extra km possible
const extraKmNumber = computed(() => Math.min(Number(extraKm.value) || 0, props.vehicleClass.km_extra_max));
function onExtraKm(event) {
    // digits only, and never above the maximum
    const digits = event.target.value.replace(/\D/g, '').slice(0, 6);
    extraKm.value = digits === '' ? '' : String(Math.min(Number(digits), props.vehicleClass.km_extra_max));
    event.target.value = extraKm.value;
}

// The reservation overview needs the accepted period and both choices.
const summary = computed(() => (period.value && fuel.value && transmission.value
    ? {
        ...period.value,
        fuel: label(props.vehicleClass.fuel_types, fuel.value),
        transmission: label(props.vehicleClass.transmissions, transmission.value),
    }
    : null));

// "2030-01-02 10:00" -> "02-01-2030 10:00"
const fmt = (s) => `${s.slice(8, 10)}-${s.slice(5, 7)}-${s.slice(0, 4)} ${s.slice(11)}`;

// The form emits the validated period (or null while a new check runs).
function onValidated(accepted) {
    choiceMissing.value = false;
    period.value = accepted;
    if (accepted && (!fuel.value || !transmission.value)) choiceMissing.value = true;
}

// The rental details were edited: the accepted period, and with it the price, is outdated until "Update".
function onChanged() {
    period.value = null;
    bookingMessages.value = [];
}

// --- price: the server calculates everything (cheapest tariff combination, own risk, extras, VAT) -------
const quote = ref(null);
const quoteError = ref('');
const quoting = ref(false);
let quoteRun = 0;
let quoteTimer = null;

async function fetchQuote() {
    const s = summary.value;
    if (!s) {
        quote.value = null;
        quoteError.value = '';
        return;
    }

    const run = ++quoteRun;
    quoting.value = true;
    try {
        const result = await api.post('/api/price-quote', {
            class_id: props.vehicleClass.id,
            fuel_type: fuel.value,
            transmission: transmission.value,
            pickup_date: s.pickupAt.slice(0, 10), pickup_time: s.pickupAt.slice(11),
            return_date: s.returnAt.slice(0, 10), return_time: s.returnAt.slice(11),
            extras: Object.entries(selectedExtras.value).map(([id, quantity]) => ({ id: Number(id), quantity })),
            reduce_own_risk: reduceOwnRisk.value,
            extra_km: extraKmNumber.value,
        });
        if (run !== quoteRun) return; // a newer request is on its way
        quote.value = result;
        kmUnavailable.value = !result.km.extra_available;
        quoteError.value = '';
    } catch (e) {
        if (run !== quoteRun) return;
        if (!(e instanceof ApiError) || e.status !== 422) throw e;

        // this fuel/transmission has no per-km price: drop the extra km and price again
        if (e.errors.extra_km) {
            extraKm.value = '';
            kmUnavailable.value = true;
            return fetchQuote();
        }
        quote.value = null;
        quoteError.value = e.message;
    } finally {
        if (run === quoteRun) quoting.value = false;
    }
}

// Re-price when anything that affects the price changes (a short delay groups quick clicks).
watch([summary, selectedExtras, reduceOwnRisk, extraKm], () => {
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(fetchQuote, 150);
}, { deep: true });

// --- the client and the booking (demo site: the server only validates and returns the order) ---------------
const client = ref({
    first_name: '', last_name: '', date_of_birth: '', street: '', house_number: '', postal_code: '', city: '',
    has_valid_license: false, accept_terms: false,
});
const fieldErrors = ref({});     // server errors on the client's fields
const bookingMessages = ref([]); // server errors about the rental (period, vehicle, price)
const booking = ref(false);
const order = ref(null);         // the order shown in the demo modal
const clientKeys = Object.keys(client.value);

async function book() {
    fieldErrors.value = {};
    bookingMessages.value = [];

    if (!summary.value) {
        bookingMessages.value = [t('checkout.client.needPeriod')];
        return;
    }
    if (quoteError.value) {
        bookingMessages.value = [quoteError.value];
        return;
    }

    const s = summary.value;
    booking.value = true;
    try {
        order.value = await api.post('/api/order-preview', {
            pickup_branch_id: s.pickupBranch.id, return_branch_id: s.returnBranch.id,
            pickup_date: s.pickupAt.slice(0, 10), pickup_time: s.pickupAt.slice(11),
            return_date: s.returnAt.slice(0, 10), return_time: s.returnAt.slice(11),
            class_id: props.vehicleClass.id, fuel_type: fuel.value, transmission: transmission.value,
            extras: Object.entries(selectedExtras.value).map(([id, quantity]) => ({ id: Number(id), quantity })),
            reduce_own_risk: reduceOwnRisk.value,
            extra_km: extraKmNumber.value,
            ...Object.fromEntries(Object.entries(client.value).map(([k, v]) => [k, typeof v === 'string' ? v.trim() : v])),
        });
    } catch (e) {
        if (!(e instanceof ApiError) || e.status !== 422) throw e;

        // client fields go next to their input, everything else (period, vehicle, price) above the button
        const own = Object.fromEntries(Object.entries(e.errors).filter(([k]) => clientKeys.includes(k)));
        fieldErrors.value = own;
        bookingMessages.value = Object.entries(e.errors).filter(([k]) => !clientKeys.includes(k)).map(([, v]) => v[0]);
        if (!Object.keys(own).length && !bookingMessages.value.length) bookingMessages.value = [e.message];
    } finally {
        booking.value = false;
    }
}

const unitCount = (unit, count) => t(`checkout.price.units.${unit}`, { count }, count);
const breakdownText = computed(() => (quote.value ? quote.value.rental.breakdown.map((b) => unitCount(b.unit, b.count)).join(' + ') : ''));
const ownRiskReduction = props.vehicleClass.own_risk_reduction;
</script>

<template>
    <h1 class="text-2xl font-semibold">{{ t('checkout.title') }}</h1>

    <div class="mt-6 grid items-start gap-6 lg:grid-cols-[1fr_22rem]">
        <div class="space-y-6">
            <RentalDetailsForm :branches="branches" :initial="initial" update auto-submit @validated="onValidated" @changed="onChanged" />
            <ExtrasPicker v-model="selectedExtras" :extras="extras" />
            <ClientForm v-model="client" :server-errors="fieldErrors" :messages="bookingMessages" :min-age="minAge" :pickup-date="period ? period.pickupAt.slice(0, 10) : null" :busy="booking" @book="book" />
        </div>

        <aside class="overflow-clip rounded-xl bg-white shadow-sm ring-1 ring-slate-200 divide-y divide-slate-100">
            <!-- The selected vehicle -->
            <div class="p-5">
                <img :src="vehicleClass.image_url" alt="" class="aspect-3/2 w-full rounded-lg bg-slate-50 object-cover">
                <div class="mt-4">
                    <p class="font-semibold leading-snug text-slate-900">
                        {{ vehicleClass.example_models }}
                        <span class="font-normal text-slate-400">{{ t('checkout.orSimilar') }}</span>
                    </p>
                    <p class="mt-0.5 text-sm text-slate-500">{{ vehicleClass.category_name }} · {{ vehicleClass.name }}</p>
                    <a :href="vehiclesUrl" class="mt-3 inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-xs font-medium text-brand-700 ring-1 ring-slate-200 transition hover:bg-brand-50 hover:ring-brand-300">{{ t('checkout.changeVehicle') }}</a>
                </div>
            </div>

            <!-- Specification: fuel and transmission -->
            <div class="space-y-4 p-5">
                <h2 class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ t('checkout.specs') }}</h2>

                <fieldset>
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <legend class="float-left text-slate-500">{{ t('checkout.fuel') }}</legend>
                        <span v-if="vehicleClass.fuel_types.length === 1" class="font-medium text-slate-900">{{ vehicleClass.fuel_types[0].label }}</span>
                    </div>
                    <div v-if="vehicleClass.fuel_types.length > 1" class="mt-2 flex flex-wrap gap-2">
                        <label v-for="o in vehicleClass.fuel_types" :key="o.value"
                               class="cursor-pointer rounded-lg px-3 py-1.5 text-sm ring-1 ring-slate-300 transition hover:ring-brand-400 has-checked:bg-brand-700 has-checked:text-white has-checked:ring-brand-700">
                            <input v-model="fuel" type="radio" name="fuel" :value="o.value" class="sr-only" @change="choiceMissing = false">
                            {{ o.label }}
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <legend class="float-left text-slate-500">{{ t('checkout.transmission') }}</legend>
                        <span class="font-medium text-slate-900">{{ label(vehicleClass.transmissions, transmission) }}</span>
                    </div>
                    <label v-for="o in alternativeTransmissions" :key="o.value"
                           class="mt-2 flex cursor-pointer items-start gap-3 rounded-lg p-3 text-sm ring-1 transition"
                           :class="transmission === o.value ? 'bg-brand-50 ring-brand-600' : 'ring-slate-200 hover:ring-brand-300'">
                        <input type="checkbox" class="mt-0.5 size-4 shrink-0 rounded border-slate-300 accent-brand-700" :checked="transmission === o.value" @change="toggleTransmission(o.value, $event.target.checked)">
                        <span class="font-medium text-slate-900">{{ o.label }}<span class="mt-0.5 block text-xs font-normal text-slate-500">{{ t('checkout.transmissionInstead', { name: label(vehicleClass.transmissions, vehicleClass.default_transmission) }) }}</span></span>
                    </label>
                </fieldset>
                <p v-if="choiceMissing" class="text-sm text-red-600">{{ t('checkout.choiceRequired') }}</p>
            </div>

            <!-- Kilometres: a flat number is included, more can be booked (optional) -->
            <fieldset class="p-5">
                <div class="flex items-center justify-between gap-3">
                    <legend class="float-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ t('checkout.km.title') }}</legend>
                    <span class="text-sm font-medium text-slate-900">{{ vehicleClass.km_included > 0 ? t('checkout.km.included', { km: vehicleClass.km_included }) : t('checkout.km.noneIncluded') }}</span>
                </div>

                <div v-if="!kmUnavailable" class="mt-3 rounded-lg bg-slate-50 p-3">
                    <label for="extra-km" class="block text-sm font-medium text-slate-700">{{ t('checkout.km.extraLabel') }}</label>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2">
                        <div class="relative w-32">
                            <input id="extra-km" type="text" inputmode="numeric" autocomplete="off" placeholder="0" :value="extraKm" aria-describedby="extra-km-hint"
                                   class="w-full rounded-lg border border-slate-300 bg-white py-2 pl-3 pr-10 text-sm focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200" @input="onExtraKm">
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-slate-400">km</span>
                        </div>
                        <span v-if="quote && quote.km.price_cents !== null" class="whitespace-nowrap text-xs text-slate-500">× {{ formatCents(quote.km.price_cents, locale) }} {{ t('checkout.km.perKm') }}</span>
                    </div>
                    <p id="extra-km-hint" class="mt-2 text-xs leading-relaxed text-slate-500">{{ t('checkout.km.extraHint', { km: vehicleClass.km_included }) }}</p>
                </div>
                <p v-else class="mt-3 text-xs text-slate-500">{{ t('checkout.km.unavailable') }}</p>
            </fieldset>

            <!-- Own risk, and lowering it -->
            <fieldset v-if="vehicleClass.own_risk !== null" class="p-5">
                <div class="flex items-center justify-between gap-3">
                    <legend class="float-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ t('checkout.price.ownRisk') }}</legend>
                    <span class="text-sm font-medium text-slate-900">{{ formatMoney(vehicleClass.own_risk, locale) }} <span class="text-xs font-normal text-slate-400">{{ t('checkout.price.exclVat') }}</span></span>
                </div>
                <label v-if="ownRiskReduction" class="mt-3 flex cursor-pointer items-start gap-3 rounded-lg p-3 text-sm ring-1 transition" :class="reduceOwnRisk ? 'bg-brand-50 ring-brand-600' : 'ring-slate-200 hover:ring-brand-300'">
                    <input v-model="reduceOwnRisk" type="checkbox" class="mt-0.5 size-4 shrink-0 rounded border-slate-300 accent-brand-700">
                    <span class="text-slate-700">{{ t('checkout.price.reduce', { reduced: formatMoney(ownRiskReduction.reduced, locale), price: formatMoney(ownRiskReduction.price_per_day, locale) }) }}</span>
                </label>
            </fieldset>

            <!-- Reservation summary, once the period was accepted and the choices are made -->
            <div class="p-5">
                <h2 class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ t('checkout.summary') }}</h2>
                <div v-if="summary" class="mt-4 space-y-5 text-sm">
                    <ol class="relative space-y-4 border-l-2 border-slate-200 pl-4">
                        <li class="relative">
                            <span class="absolute -left-[23px] top-0.5 size-3 rounded-full border-2 border-brand-700 bg-brand-700 ring-4 ring-white"></span>
                            <p class="text-xs font-medium text-slate-500">{{ t('checkout.pickup') }}</p>
                            <p class="font-medium text-slate-900">{{ summary.pickupBranch.city }} – {{ summary.pickupBranch.name }}</p>
                            <p class="text-slate-600">{{ fmt(summary.pickupAt) }}</p>
                        </li>
                        <li class="relative">
                            <span class="absolute -left-[23px] top-0.5 size-3 rounded-full border-2 border-brand-700 bg-white ring-4 ring-white"></span>
                            <p class="text-xs font-medium text-slate-500">{{ t('checkout.return') }}</p>
                            <p class="font-medium text-slate-900">{{ summary.returnBranch.city }} – {{ summary.returnBranch.name }}</p>
                            <p class="text-slate-600">{{ fmt(summary.returnAt) }}</p>
                        </li>
                    </ol>

                    <p class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2">
                        <span class="text-slate-500">{{ t('checkout.vehicle') }}</span>
                        <span class="text-right font-medium text-slate-900">{{ vehicleClass.name }} · {{ summary.fuel }} · {{ summary.transmission }}</span>
                    </p>

                    <!-- Price build-up from the server -->
                    <div v-if="quote" :class="{ 'opacity-60': quoting }">
                        <p class="mb-2 flex items-baseline justify-between gap-3 text-xs font-medium text-slate-500">
                            <span>{{ t('checkout.price.title') }}</span><span>{{ t('checkout.price.days', { count: quote.days }, quote.days) }}</span>
                        </p>
                        <ul class="space-y-2.5">
                            <li class="flex justify-between gap-3">
                                <span>{{ t('checkout.price.rental') }}<span class="block text-xs text-slate-500">{{ breakdownText }}</span></span>
                                <span class="whitespace-nowrap font-medium tabular-nums">{{ formatCents(quote.rental.cents, locale) }}</span>
                            </li>
                            <li v-if="quote.own_risk.reduction_chosen" class="flex justify-between gap-3">
                                <span>{{ t('checkout.price.ownRiskReduction') }}<span class="block text-xs text-slate-500">{{ t('checkout.price.ownRiskTo', { amount: formatCents(quote.own_risk.reduced_cents, locale) }) }}</span></span>
                                <span class="whitespace-nowrap font-medium tabular-nums">{{ formatCents(quote.own_risk.reduction_cents, locale) }}</span>
                            </li>
                            <li v-if="quote.km.extra > 0" class="flex justify-between gap-3">
                                <span>{{ t('checkout.km.extraLine') }}<span class="block text-xs text-slate-500">{{ quote.km.extra }} km × {{ formatCents(quote.km.price_cents, locale) }}</span></span>
                                <span class="whitespace-nowrap font-medium tabular-nums">{{ formatCents(quote.km.extra_cents, locale) }}</span>
                            </li>
                            <li v-for="x in quote.extras" :key="x.id" class="flex justify-between gap-3">
                                <span>{{ x.title }}<span v-if="x.quantity > 1" class="text-slate-500"> × {{ x.quantity }}</span></span>
                                <span class="whitespace-nowrap font-medium tabular-nums">{{ formatCents(x.cents, locale) }}</span>
                            </li>
                        </ul>
                        <KmNote :km="quote.km" class="mt-3" />
                    </div>
                    <p v-else-if="quoteError" class="rounded-lg bg-amber-50 p-3 text-xs text-amber-800">{{ quoteError }}</p>
                    <p v-else class="text-xs text-slate-400">{{ t('checkout.price.calculating') }}</p>

                    <p class="rounded-lg bg-brand-50 p-3 text-xs leading-relaxed text-brand-800">{{ t('checkout.nextSoon') }}</p>
                </div>
                <p v-else class="mt-3 text-sm text-slate-400">{{ t('checkout.summaryEmpty') }}</p>
            </div>

            <!-- Totals: only when everything can be calculated; sticks to the bottom of the screen while the overview scrolls -->
            <div v-if="quote" class="sticky bottom-0 bg-white/95 px-5 py-4 shadow-[0_-6px_12px_-6px_rgba(15,23,42,0.12)] backdrop-blur transition-opacity" :class="{ 'opacity-60': quoting }" aria-live="polite">
                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-600">{{ t('checkout.price.totalExcl') }}</dt><dd class="font-medium tabular-nums">{{ formatCents(quote.subtotal_cents, locale) }}</dd></div>
                    <div class="flex justify-between gap-3 text-xs text-slate-500"><dt>{{ t('checkout.price.vat', { rate: quote.vat_rate }) }}</dt><dd class="tabular-nums">{{ formatCents(quote.vat_cents, locale) }}</dd></div>
                    <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-2"><dt class="font-semibold">{{ t('checkout.price.totalIncl') }}</dt><dd class="text-xl font-bold tabular-nums text-brand-800">{{ formatCents(quote.total_cents, locale) }}</dd></div>
                </dl>
                <!-- Deposit: refundable, so not part of the total -->
                <p v-if="quote.deposit.applicable" class="mt-2 flex items-baseline justify-between gap-3 border-t border-dashed border-slate-200 pt-2 text-sm">
                    <span class="text-slate-600">{{ t('checkout.price.deposit') }}</span>
                    <strong class="tabular-nums">{{ formatCents(quote.deposit.cents, locale) }}</strong>
                </p>
                <p v-else-if="quote.deposit.reason === 'leasing'" class="mt-2 border-t border-dashed border-slate-200 pt-2 text-xs text-slate-500">{{ t('checkout.price.noDeposit', { days: quote.deposit.leasing_from_days }) }}</p>
            </div>
        </aside>
    </div>

    <OrderPreviewModal v-if="order" :order="order" @close="order = null" />
</template>
