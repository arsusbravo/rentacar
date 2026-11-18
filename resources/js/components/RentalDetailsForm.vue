<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import { enGB, nl } from 'date-fns/locale';
import { api, ApiError } from '../api';
import CategoryPicker from './CategoryPicker.vue';

const props = defineProps({
    branches: { type: Array, default: () => [] },
    // Vehicle types to choose from ({ slug, name }). When given, the form has a (required) type field.
    categories: { type: Array, default: () => [] },
    // Prefill: { category, pickup, return, pickupDate, pickupTime, returnDate, returnTime }
    initial: { type: Object, default: () => ({}) },
    // Small card layout (homepage hero) instead of the two-column checkout layout.
    compact: { type: Boolean, default: false },
    // When set, a valid choice goes to this URL (with the choice as query) instead of emitting 'validated'.
    redirectTo: { type: String, default: null },
    // Label the button "Search" instead of "Continue".
    search: { type: Boolean, default: false },
    // Label the button "Update": the form already holds a period and the button applies changes to it (checkout).
    update: { type: Boolean, default: false },
    // Validate a complete prefill right away (without a click), e.g. when the checkout opens.
    autoSubmit: { type: Boolean, default: false },
    // Low, wide search bar (vehicles page): chips for the type, all fields in one row on large screens.
    bar: { type: Boolean, default: false },
    // On a phone: fold the form into a one-line summary once everything is filled in (tap to change).
    collapsible: { type: Boolean, default: false },
});
const emit = defineEmits(['validated', 'changed', 'query']);
const { t, locale } = useI18n();

// --- form state -----------------------------------------------------------
const categorySlug = ref('');
const pickupBranchId = ref('');
const returnBranchId = ref('');
const returnBranchTouched = ref(false);
const pickupDate = ref(null); // 'yyyy-MM-dd'
const pickupTime = ref('');
const returnDate = ref(null);
const returnTime = ref('');
const errors = ref({});
const submitting = ref(false);
const folded = ref(false); // collapsible: the form is folded into the summary row (phones only)

// --- helpers --------------------------------------------------------------
const pad = (n) => String(n).padStart(2, '0');
const toDateString = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const isoWeekday = (d) => ((d.getDay() + 6) % 7) + 1; // 1 = Monday ... 7 = Sunday
const startOfToday = () => { const d = new Date(); d.setHours(0, 0, 0, 0); return d; };

/** "HH:MM" slots every 30 minutes from `from` up to and including `to`. */
function slots(from, to) {
    const toMin = (s) => Number(s.slice(0, 2)) * 60 + Number(s.slice(3, 5));
    const out = [];
    for (let m = toMin(from); m <= toMin(to); m += 30) out.push(`${pad(Math.floor(m / 60))}:${pad(m % 60)}`);
    return out;
}

// "Rentacar Den Haag" already holds the city, so only add it when the name does not.
const branchLabel = (b) => (b.name.toLowerCase().includes(b.city.toLowerCase()) ? b.name : `${b.city} – ${b.name}`);

const dateLocale = computed(() => (locale.value === 'nl' ? nl : enGB));
const pickupBranch = computed(() => props.branches.find((b) => b.id === pickupBranchId.value));

/** Possible pick-up times on a date: limited to the branch opening hours. */
function pickupSlotsFor(branch, date) {
    const hours = branch?.opening_hours?.find((h) => h.weekday === isoWeekday(date));
    if (!hours || hours.is_closed) return [];

    let list = slots(hours.opens_at, hours.closes_at);
    if (toDateString(date) === toDateString(new Date())) {
        const now = `${pad(new Date().getHours())}:${pad(new Date().getMinutes())}`;
        list = list.filter((s) => s > now);
    }
    return list;
}

// --- pick-up --------------------------------------------------------------
const pickupDateObj = computed(() => (pickupDate.value ? new Date(`${pickupDate.value}T00:00:00`) : null));
const pickupSlots = computed(() => (pickupDateObj.value ? pickupSlotsFor(pickupBranch.value, pickupDateObj.value) : []));

// Disabled: no branch yet, past days, and days the branch is closed (e.g. weekends).
const isPickupDateDisabled = (date) => !pickupBranch.value || date < startOfToday() || pickupSlotsFor(pickupBranch.value, date).length === 0;

// --- return (24 hours a day, any day, but never before the pick-up) ---------
const returnSlots = computed(() => {
    const all = slots('00:00', '23:30');
    return returnDate.value && returnDate.value === pickupDate.value ? all.filter((s) => s > pickupTime.value) : all;
});
const isReturnDateDisabled = (date) => {
    const str = toDateString(date);
    if (!pickupDate.value || str < pickupDate.value) return true;
    return str === pickupDate.value && !slots('00:00', '23:30').some((s) => s > pickupTime.value);
};

// --- keep the fields consistent -------------------------------------------
watch(pickupBranchId, (id) => {
    if (!returnBranchTouched.value) returnBranchId.value = id;
    if (pickupDateObj.value && isPickupDateDisabled(pickupDateObj.value)) pickupDate.value = null;
});
watch([pickupDate, pickupBranchId], () => {
    if (!pickupSlots.value.includes(pickupTime.value)) pickupTime.value = '';
});
watch([pickupDate, pickupTime], () => {
    if (returnDate.value && (!pickupTime.value || returnDate.value < pickupDate.value)) returnDate.value = null;
});
watch([returnDate, pickupDate, pickupTime], () => {
    if (!returnDate.value || !returnSlots.value.includes(returnTime.value)) returnTime.value = '';
});

// Any change to the rental details makes an earlier accepted period outdated (the checkout then asks to update).
watch([pickupBranchId, returnBranchId, pickupDate, pickupTime, returnDate, returnTime], () => {
    emit('changed');
    emit('query', currentQuery());
});

// What is filled in right now, as the URL query the vehicle links and the checkout understand.
// Emitted on every change ('query'), so links elsewhere on the page can carry the values without pressing Search.
function currentQuery() {
    return Object.fromEntries(Object.entries({
        pickup: pickupBranchId.value, return: returnBranchId.value,
        pickup_date: pickupDate.value, pickup_time: pickupTime.value,
        return_date: returnDate.value, return_time: returnTime.value,
    }).filter(([, value]) => value !== '' && value !== null && value !== undefined));
}

// Apply a prefill. The watchers above drop any value that is no longer allowed
// (e.g. a weekend date or a time outside the opening hours).
onMounted(async () => {
    const i = props.initial;
    if (props.categories.some((c) => c.slug === i.category)) categorySlug.value = i.category;
    const known = (id) => props.branches.some((b) => b.id === id);
    if (known(i.pickup)) pickupBranchId.value = i.pickup;
    if (known(i.return) && i.return !== i.pickup) {
        returnBranchId.value = i.return;
        returnBranchTouched.value = true;
    }
    pickupDate.value = i.pickupDate ?? null;
    pickupTime.value = i.pickupTime ?? '';
    returnDate.value = i.returnDate ?? null;
    returnTime.value = i.returnTime ?? '';

    // After the watchers had their say: they fill the return location and drop values that are not allowed
    // (weekend, outside opening hours).
    await nextTick();

    // A complete period is accepted straight away, so the checkout can show the price without a click.
    if (props.autoSubmit && complete.value) submit();

    // A form that arrives complete (prefilled) starts folded on a phone.
    if (props.collapsible && complete.value) folded.value = true;
});

const complete = computed(() => !!(pickupBranchId.value && returnBranchId.value && pickupDate.value && pickupTime.value && returnDate.value && returnTime.value));
const collapsed = computed(() => props.collapsible && complete.value && folded.value);

// One-line summary of what is filled in: "Personenauto · Rentacar Den Haag" / "19-11 13:00 → 20-11 11:30"
const shortDate = (d) => `${d.slice(8, 10)}-${d.slice(5, 7)}`;
const summaryTitle = computed(() => {
    const from = pickupBranch.value ? branchLabel(pickupBranch.value) : '';
    const back = props.branches.find((b) => b.id === returnBranchId.value);
    const place = back && back.id !== pickupBranch.value?.id ? `${from} → ${branchLabel(back)}` : from;
    const category = props.categories.find((c) => c.slug === categorySlug.value)?.name;
    return [category, place].filter(Boolean).join(' · ');
});
const summaryPeriod = computed(() => (complete.value ? `${shortDate(pickupDate.value)} ${pickupTime.value} → ${shortDate(returnDate.value)} ${returnTime.value}` : ''));

// --- submit ---------------------------------------------------------------
async function submit() {
    errors.value = {};
    emit('validated', null);

    if (props.categories.length && !categorySlug.value) {
        errors.value = { category: [t('checkout.categoryRequired')] };
        return;
    }

    submitting.value = true;
    try {
        await api.post('/api/rental-period/validate', {
            category: categorySlug.value || null,
            pickup_branch_id: pickupBranchId.value || null,
            return_branch_id: returnBranchId.value || null,
            pickup_date: pickupDate.value,
            pickup_time: pickupTime.value || null,
            return_date: returnDate.value,
            return_time: returnTime.value || null,
        });
        if (props.redirectTo) {
            const query = new URLSearchParams({
                ...(categorySlug.value ? { category: categorySlug.value } : {}),
                pickup: pickupBranchId.value, return: returnBranchId.value,
                pickup_date: pickupDate.value, pickup_time: pickupTime.value,
                return_date: returnDate.value, return_time: returnTime.value,
            });
            window.location.assign(`${props.redirectTo}?${query}`);
            return;
        }
        emit('validated', {
            pickupBranch: pickupBranch.value,
            returnBranch: props.branches.find((b) => b.id === returnBranchId.value),
            pickupAt: `${pickupDate.value} ${pickupTime.value}`,
            returnAt: `${returnDate.value} ${returnTime.value}`,
        });
        folded.value = true; // accepted: fold the form away (phones)
    } catch (e) {
        if (e instanceof ApiError && e.status === 422) errors.value = e.errors;
        else throw e;
    } finally {
        submitting.value = false;
    }
}

const errorList = computed(() => Object.values(errors.value).flat());
const field = 'mt-1 w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-900 disabled:bg-slate-100 disabled:text-slate-400';
const formClass = computed(() => {
    if (props.compact) return 'w-full rounded-xl bg-white/95 p-4 shadow-xl backdrop-blur';
    if (props.bar) return 'rounded-xl bg-white p-3.5 shadow-sm ring-1 ring-slate-200 lg:grid lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end lg:gap-x-3';
    return 'rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200';
});
const short = computed(() => props.compact || props.bar); // "Pick-up date" instead of "Date" (no Pick-up/Return heading)
const pickerSize = computed(() => (props.bar ? 'sm' : props.compact ? 'md' : 'lg'));
// Bar layout: location | date | time, two groups side by side (pick-up, return)
const barGroup = 'grid grid-cols-2 gap-2 sm:grid-cols-[minmax(0,1.5fr)_minmax(0,1.1fr)_minmax(0,.8fr)]';
const dpUi = { input: 'dp-tw-input' };
const dpFormats = { input: 'dd-MM-yyyy' };
const dpTime = { enableTimePicker: false };
</script>

<template>
    <form :class="[formClass, 'text-left text-brand-950']" novalidate @submit.prevent="submit">
        <!-- Phone: a one-line summary instead of the whole form; tap to change -->
        <button v-if="collapsible && complete" type="button" :aria-expanded="!collapsed" aria-controls="rental-fields"
                class="flex w-full items-center justify-between gap-3 text-left lg:hidden" :class="collapsed ? '' : 'mb-3 border-b border-slate-100 pb-3'" @click="folded = !folded">
            <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-slate-900">{{ summaryTitle }}</span>
                <span class="block truncate text-xs text-slate-500">{{ summaryPeriod }}</span>
            </span>
            <span class="flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-brand-700 ring-1 ring-slate-200">
                {{ collapsed ? t('checkout.change') : t('checkout.hide') }}
                <svg class="size-3.5 transition" :class="collapsed ? '' : 'rotate-180'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 8 5 5 5-5" /></svg>
            </span>
        </button>

        <div id="rental-fields" class="contents" :class="{ 'max-lg:hidden': collapsed }">
        <div v-if="categories.length" :class="bar ? 'mb-3 flex flex-wrap items-center gap-x-4 gap-y-2 lg:col-span-2' : compact ? 'mb-3' : 'mb-6'">
            <CategoryPicker v-model="categorySlug" :categories="categories" :size="pickerSize" />
            <!-- the opening-hours note as a tooltip, to keep the bar low -->
            <span v-if="bar" class="inline-flex size-5 cursor-help items-center justify-center rounded-full text-xs font-semibold text-slate-400 ring-1 ring-slate-300 hover:text-brand-700 hover:ring-brand-400"
                  role="note" tabindex="0" :title="t('checkout.hoursHint')" :aria-label="t('checkout.hoursHint')">i</span>
        </div>

        <div :class="bar ? 'grid gap-3 lg:grid-cols-2' : compact ? 'space-y-2.5' : 'grid gap-6 md:grid-cols-2'">
            <fieldset :class="bar ? barGroup : 'space-y-2.5'">
                <legend :class="short ? 'sr-only' : 'mb-1 text-base font-semibold'">{{ t('checkout.pickup') }}</legend>
                <label :class="bar ? 'col-span-2 block text-xs font-medium text-slate-500 sm:col-span-1' : 'block text-xs font-medium text-slate-500'">{{ t('checkout.pickupLocation') }}
                    <select v-model="pickupBranchId" :class="field">
                        <option value="">{{ t('checkout.chooseBranch') }}</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ branchLabel(b) }}</option>
                    </select>
                </label>
                <div :class="bar ? 'contents' : 'grid grid-cols-[3fr_2fr] gap-2'">
                    <div class="text-xs font-medium text-slate-500">{{ short ? t('checkout.pickupDate') : t('checkout.date') }}
                        <VueDatePicker v-model="pickupDate" model-type="yyyy-MM-dd" :formats="dpFormats" :time-config="dpTime"
                                       :locale="dateLocale" :week-start="1" auto-apply teleport :ui="dpUi" :clearable="false"
                                       :disabled="!pickupBranch" :min-date="startOfToday()" :disabled-dates="isPickupDateDisabled"
                                       :placeholder="t('checkout.selectDate')" />
                    </div>
                    <label class="block text-xs font-medium text-slate-500">{{ short ? t('checkout.pickupTime') : t('checkout.time') }}
                        <select v-model="pickupTime" :class="field" :disabled="!pickupDate">
                            <option value="">--:--</option>
                            <option v-for="s in pickupSlots" :key="s" :value="s">{{ s }}</option>
                        </select>
                    </label>
                </div>
            </fieldset>

            <fieldset :class="bar ? barGroup : compact ? 'space-y-2.5 pt-1' : 'space-y-2.5'">
                <legend :class="short ? 'sr-only' : 'mb-1 text-base font-semibold'">{{ t('checkout.return') }}</legend>
                <label :class="bar ? 'col-span-2 block text-xs font-medium text-slate-500 sm:col-span-1' : 'block text-xs font-medium text-slate-500'">{{ t('checkout.dropLocation') }}
                    <select v-model="returnBranchId" :class="field" @change="returnBranchTouched = true">
                        <option value="">{{ t('checkout.chooseBranch') }}</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ branchLabel(b) }}</option>
                    </select>
                </label>
                <div :class="bar ? 'contents' : 'grid grid-cols-[3fr_2fr] gap-2'">
                    <div class="text-xs font-medium text-slate-500">{{ short ? t('checkout.returnDate') : t('checkout.date') }}
                        <VueDatePicker v-model="returnDate" model-type="yyyy-MM-dd" :formats="dpFormats" :time-config="dpTime"
                                       :locale="dateLocale" :week-start="1" auto-apply teleport :ui="dpUi" :clearable="false"
                                       :disabled="!pickupTime" :min-date="pickupDate || startOfToday()" :disabled-dates="isReturnDateDisabled"
                                       :placeholder="t('checkout.selectDate')" />
                    </div>
                    <label class="block text-xs font-medium text-slate-500">{{ short ? t('checkout.returnTime') : t('checkout.time') }}
                        <select v-model="returnTime" :class="field" :disabled="!returnDate">
                            <option value="">--:--</option>
                            <option v-for="s in returnSlots" :key="s" :value="s">{{ s }}</option>
                        </select>
                    </label>
                </div>
            </fieldset>
        </div>

        <p v-if="!compact && !bar" class="mt-4 text-xs text-slate-500">{{ t('checkout.hoursHint') }}</p>

        <ul v-if="errorList.length" :class="bar ? 'mt-3 list-disc space-y-1 pl-5 text-xs text-red-600 lg:col-span-2 lg:row-start-3' : 'mt-3 list-disc space-y-1 pl-5 text-xs text-red-600'">
            <li v-for="(e, i) in errorList" :key="i">{{ e }}</li>
        </ul>

        <button :disabled="submitting" :class="bar ? 'mt-3 px-6 py-2 lg:col-start-2 lg:row-start-2 lg:mt-0' : compact ? 'mt-3 w-full px-4 py-2' : 'mt-4 px-6 py-2.5'"
                class="rounded-lg bg-accent-500 text-sm font-semibold text-brand-950 hover:bg-accent-400 disabled:opacity-60">
            {{ search ? t('checkout.search') : update ? t('checkout.update') : t('checkout.continue') }}
        </button>
        <p v-if="compact" class="mt-2 text-[11px] leading-snug text-slate-500">{{ t('checkout.hoursHint') }}</p>
        </div>
    </form>
</template>

<style>
/* Match the datepicker input with the other form fields. */
.dp-tw-input {
    margin-top: 0.25rem;
    border-radius: 0.5rem;
    border: 1px solid #cbd5e1;
    padding: 0.375rem 0.5rem 0.375rem 2.25rem;
    font-size: 0.875rem;
    color: #0f172a;
}
</style>
