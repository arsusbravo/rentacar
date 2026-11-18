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
const detailsForm = ref(null); // the rental details form
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

// "Book" first needs the rental details to be complete: the form opens and shows what is missing.
function detailsComplete() {
    if (detailsForm.value?.check()) return true;
    fieldErrors.value = {};
    bookingMessages.value = [t('checkout.incompleteBook')];
    return false;
}

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

// Specifications under the vehicle (fuel, transmission and km are chosen on the left, so they are not repeated).
const specs = computed(() => {
    const c = props.vehicleClass;
    const luggage = [c.baggage_large ? t('checkout.spec.large', { count: c.baggage_large }) : null, c.baggage_small ? t('checkout.spec.small', { count: c.baggage_small }) : null].filter(Boolean).join(' + ');
    return [
        { label: t('checkout.spec.class'), value: c.name, wide: true },
        c.seats ? { label: t('checkout.spec.seats'), value: c.seats } : null,
        c.doors ? { label: t('checkout.spec.doors'), value: c.doors } : null,
        luggage ? { label: t('checkout.spec.luggage'), value: luggage, wide: true } : null,
        c.cargo_volume_liters ? { label: t('checkout.spec.cargo'), value: `${c.cargo_volume_liters.toLocaleString(locale.value === 'nl' ? 'nl-NL' : 'en-GB')} L` } : null,
        c.payload_kg ? { label: t('checkout.spec.payload'), value: `${c.payload_kg.toLocaleString(locale.value === 'nl' ? 'nl-NL' : 'en-GB')} kg` } : null,
        c.license_category ? { label: t('checkout.spec.license'), value: c.license_category } : null,
    ].filter(Boolean);
});
</script>

<template>
    <h1 class="text-2xl font-semibold">{{ t('checkout.title') }}</h1>

    <div class="mt-6 grid items-start gap-6 max-lg:pb-20 lg:grid-cols-[1fr_22rem]">
        <div class="space-y-6">
            <RentalDetailsForm ref="detailsForm" :branches="branches" :initial="initial" update auto-submit collapsible @validated="onValidated" @changed="onChanged" />

            <!-- Fuel, transmission, kilometres and own risk: one compact card, a row each -->
            <section class="rounded-xl bg-white px-5 py-2 shadow-sm ring-1 ring-slate-200" aria-labelledby="build-title">
                <h2 id="build-title" class="sr-only">{{ t('checkout.specs') }}</h2>
                <div class="divide-y divide-slate-100 text-sm">
                    <!-- Fuel -->
                    <fieldset class="grid items-center gap-x-4 gap-y-1.5 py-3 sm:grid-cols-[8.5rem_1fr]">
                        <legend class="float-left font-medium text-slate-700 sm:float-none sm:contents"><span class="sm:col-start-1">{{ t('checkout.fuel') }}</span></legend>
                        <span v-if="vehicleClass.fuel_types.length === 1" class="font-medium">{{ vehicleClass.fuel_types[0].label }}</span>
                        <div v-else class="flex flex-wrap gap-2">
                            <label v-for="o in vehicleClass.fuel_types" :key="o.value"
                                   class="cursor-pointer rounded-lg px-3.5 py-1.5 font-medium ring-1 ring-slate-300 transition hover:ring-brand-400 has-checked:bg-brand-700 has-checked:text-white has-checked:ring-brand-700">
                                <input v-model="fuel" type="radio" name="fuel" :value="o.value" class="sr-only" @change="choiceMissing = false">
                                {{ o.label }}
                            </label>
                        </div>
                    </fieldset>

                    <!-- Transmission: the default, or a tick for the alternative -->
                    <fieldset class="grid items-center gap-x-4 gap-y-1.5 py-3 sm:grid-cols-[8.5rem_1fr]">
                        <legend class="float-left font-medium text-slate-700 sm:float-none sm:contents"><span class="sm:col-start-1">{{ t('checkout.transmission') }}</span></legend>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <span class="font-medium">{{ label(vehicleClass.transmissions, transmission) }}</span>
                            <label v-for="o in alternativeTransmissions" :key="o.value"
                                   class="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs ring-1 transition"
                                   :class="transmission === o.value ? 'bg-brand-700 text-white ring-brand-700' : 'bg-brand-50 text-brand-900 ring-brand-200 hover:bg-brand-100 hover:ring-brand-400'">
                                <input type="checkbox" class="size-4 shrink-0 cursor-pointer rounded border-brand-300 accent-brand-700" :checked="transmission === o.value" @change="toggleTransmission(o.value, $event.target.checked)">
                                <span><strong class="font-semibold">{{ o.label }}</strong> <span class="opacity-70">{{ t('checkout.transmissionInstead', { name: label(vehicleClass.transmissions, vehicleClass.default_transmission) }) }}</span></span>
                            </label>
                        </div>
                    </fieldset>
                    <p v-if="choiceMissing" class="py-2 text-sm text-red-600">{{ t('checkout.choiceRequired') }}</p>

                    <!-- Kilometres -->
                    <div class="grid items-start gap-x-4 gap-y-1.5 py-3 sm:grid-cols-[8.5rem_1fr]">
                        <p class="font-medium text-slate-700">{{ t('checkout.km.title') }}</p>
                        <div>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                <span class="font-medium">{{ vehicleClass.km_included > 0 ? t('checkout.km.included', { km: vehicleClass.km_included }) : t('checkout.km.noneIncluded') }}</span>
                                <template v-if="!kmUnavailable">
                                    <label for="extra-km" class="text-slate-500">{{ t('checkout.km.extraLabel') }}</label>
                                    <div class="relative w-28">
                                        <input id="extra-km" type="text" inputmode="numeric" autocomplete="off" placeholder="0" :value="extraKm" aria-describedby="extra-km-hint"
                                               class="w-full rounded-lg border border-slate-300 bg-white py-1.5 pl-3 pr-9 focus:border-brand-500 focus:outline-hidden focus:ring-2 focus:ring-brand-200" @input="onExtraKm">
                                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-slate-400">km</span>
                                    </div>
                                    <span v-if="quote && quote.km.price_cents !== null" class="whitespace-nowrap text-xs text-slate-500">× {{ formatCents(quote.km.price_cents, locale) }} {{ t('checkout.km.perKm') }}</span>
                                </template>
                            </div>
                            <p v-if="!kmUnavailable" id="extra-km-hint" class="mt-1.5 text-xs text-slate-500">{{ t('checkout.km.extraHint', { km: vehicleClass.km_included }) }}</p>
                            <p v-else class="mt-1.5 text-xs text-slate-500">{{ t('checkout.km.unavailable') }}</p>
                        </div>
                    </div>

                    <!-- Own risk, and lowering it -->
                    <div v-if="vehicleClass.own_risk !== null" class="grid items-center gap-x-4 gap-y-1.5 py-3 sm:grid-cols-[8.5rem_1fr]">
                        <p class="font-medium text-slate-700">{{ t('checkout.price.ownRisk') }}</p>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <span class="font-medium">{{ formatMoney(vehicleClass.own_risk, locale) }} <span class="text-xs font-normal text-slate-400">{{ t('checkout.price.exclVat') }}</span></span>
                            <label v-if="ownRiskReduction" class="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs ring-1 transition" :class="reduceOwnRisk ? 'bg-brand-700 text-white ring-brand-700' : 'bg-brand-50 text-brand-900 ring-brand-200 hover:bg-brand-100 hover:ring-brand-400'">
                                <input v-model="reduceOwnRisk" type="checkbox" class="size-4 shrink-0 cursor-pointer rounded border-brand-300 accent-brand-700">
                                <span>{{ t('checkout.price.reduce', { reduced: formatMoney(ownRiskReduction.reduced, locale), price: formatMoney(ownRiskReduction.price_per_day, locale) }) }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            <ExtrasPicker v-model="selectedExtras" :extras="extras" />
            <ClientForm v-model="client" :server-errors="fieldErrors" :messages="bookingMessages" :min-age="minAge" :pickup-date="period ? period.pickupAt.slice(0, 10) : null" :busy="booking" :before-book="detailsComplete" @book="book" />
        </div>

        <!-- Right: the vehicle and the prices only -->
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

                <!-- Specifications, always visible -->
                <div v-if="specs.length" class="mt-4">
                    <h2 class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ t('checkout.specifications') }}</h2>
                    <dl class="mt-2 grid grid-flow-dense grid-cols-2 gap-2">
                        <div v-for="sp in specs" :key="sp.label" class="rounded-lg bg-sky-50 px-3 py-2" :class="{ 'col-span-2': sp.wide }">
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-sky-600">{{ sp.label }}</dt>
                            <dd class="text-sm font-semibold text-sky-950">{{ sp.value }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

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
            <div v-if="quote" id="overview-totals" class="bg-white/95 px-5 py-4 lg:sticky lg:bottom-0 shadow-[0_-6px_12px_-6px_rgba(15,23,42,0.12)] backdrop-blur transition-opacity" :class="{ 'opacity-60': quoting }" aria-live="polite">
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

    <!-- Phone: the total flies in at the bottom of the screen as soon as it is known -->
    <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-y-full opacity-0" leave-active-class="transition duration-200 ease-in" leave-to-class="translate-y-full opacity-0">
        <a v-if="quote" href="#overview-totals" class="fixed inset-x-0 bottom-0 z-30 flex items-center justify-between gap-3 border-t border-slate-200 bg-white/95 px-4 py-3 shadow-[0_-6px_16px_-6px_rgba(15,23,42,0.2)] backdrop-blur lg:hidden" :class="{ 'opacity-70': quoting }" aria-live="polite">
            <span>
                <span class="block text-xs text-slate-500">{{ t('checkout.price.totalIncl') }}</span>
                <span v-if="quote.deposit.applicable" class="block text-[11px] text-slate-400">{{ t('checkout.price.deposit') }} {{ formatCents(quote.deposit.cents, locale) }}</span>
            </span>
            <span class="text-xl font-bold tabular-nums text-brand-800">{{ formatCents(quote.total_cents, locale) }}</span>
        </a>
    </Transition>

    <OrderPreviewModal v-if="order" :order="order" @close="order = null" />
</template>
