<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

// The client's own details, the two confirmations and the booking button. Checks the input as the server
// does (same formats), so mistakes show before anything is sent; errors from the server are shown too.
const props = defineProps({
    modelValue: { type: Object, required: true },
    serverErrors: { type: Object, default: () => ({}) }, // { field: [message] } from the server
    messages: { type: Array, default: () => [] },        // general messages about the rental (not a field)
    minAge: { type: Number, default: 18 },
    pickupDate: { type: String, default: null },         // 'yyyy-MM-dd', the date the minimum age is checked on
    busy: { type: Boolean, default: false },
    beforeBook: { type: Function, default: null },       // called first; returning false stops the booking
});
const emit = defineEmits(['update:modelValue', 'book']);

const { t, locale } = useI18n();
const errors = reactive({});
const root = ref(null);

// Same rules as App\Http\Requests\OrderPreviewRequest.
const NAME = /^[\p{L}\s'.-]+$/u;
const HOUSE = /^\d{1,5}([\s\-/]?[A-Za-z0-9]{1,5})?$/;
const POSTAL = /^[1-9][0-9]{3}\s?[A-Za-z]{2}$/;

function check() {
    const v = props.modelValue;
    const out = {};
    const required = (key) => { if (!String(v[key] ?? '').trim()) out[key] = t('checkout.client.errors.required'); return !out[key]; };

    if (required('first_name') && !NAME.test(v.first_name.trim())) out.first_name = t('checkout.client.errors.name');
    if (required('last_name') && !NAME.test(v.last_name.trim())) out.last_name = t('checkout.client.errors.name');

    if (!String(v.date_of_birth ?? '').trim()) {
        out.date_of_birth = t('checkout.client.errors.dobIncomplete');
    } else {
        const born = new Date(`${v.date_of_birth}T00:00:00`);
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const onDate = new Date(`${props.pickupDate ?? today.toISOString().slice(0, 10)}T00:00:00`);
        const adult = new Date(born); adult.setFullYear(adult.getFullYear() + props.minAge);

        // an impossible date (31 February) rolls over to another day: that is not the date that was chosen
        const [yy, mm, dd] = v.date_of_birth.split('-').map(Number);
        const real = !Number.isNaN(born.getTime()) && born.getFullYear() === yy && born.getMonth() + 1 === mm && born.getDate() === dd;

        if (!real || born < new Date('1900-01-02T00:00:00')) out.date_of_birth = t('checkout.client.errors.dobInvalid');
        else if (born >= today) out.date_of_birth = t('checkout.client.errors.dobFuture');
        else if (adult > onDate) out.date_of_birth = t('checkout.client.errors.tooYoung', { age: props.minAge });
    }

    if (required('street') && v.street.trim().length > 120) out.street = t('checkout.client.errors.long');
    if (required('house_number') && (v.house_number.trim().length > 10 || !HOUSE.test(v.house_number.trim()))) out.house_number = t('checkout.client.errors.house');
    if (required('postal_code') && !POSTAL.test(v.postal_code.trim())) out.postal_code = t('checkout.client.errors.postal');
    if (required('city') && !NAME.test(v.city.trim())) out.city = t('checkout.client.errors.city');

    if (!v.has_valid_license) out.has_valid_license = t('checkout.client.errors.license');
    if (!v.accept_terms) out.accept_terms = t('checkout.client.errors.terms');

    return out;
}

async function book() {
    // the rental details above must be complete first (the checkout opens and marks them)
    if (props.beforeBook && !props.beforeBook()) return;

    const found = check();
    Object.keys(errors).forEach((k) => delete errors[k]);
    Object.assign(errors, found);

    const first = Object.keys(found)[0];
    if (first) {
        await nextTick();
        root.value.querySelector(`[name="${first}"]`)?.focus();
        return;
    }
    emit('book');
}

// Errors from the server replace the local ones for the fields they name.
watch(() => props.serverErrors, async (server) => {
    Object.keys(errors).forEach((k) => delete errors[k]);
    for (const [key, list] of Object.entries(server)) errors[key] = list[0];

    const first = Object.keys(server).find((k) => root.value?.querySelector(`[name="${k}"]`));
    if (first) {
        await nextTick();
        root.value.querySelector(`[name="${first}"]`).focus();
    }
});

function set(key, value) {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
    delete errors[key]; // the mistake is being fixed
}

// Date of birth as three dropdowns (day, month, year): far easier than paging through years in a date picker.
// The model keeps one 'yyyy-MM-dd' string ('' until all three are chosen).
const pad = (n) => String(n).padStart(2, '0');
const parts = computed(() => {
    const [y = '', m = '', d = ''] = (props.modelValue.date_of_birth || '').split('-');
    return { year: y, month: m ? String(Number(m)) : '', day: d ? String(Number(d)) : '' };
});
const picked = reactive({ day: '', month: '', year: '' });
watch(() => props.modelValue.date_of_birth, () => Object.assign(picked, parts.value), { immediate: true });

function setDob(part, value) {
    picked[part] = value;
    const { day, month, year } = picked;
    // all three chosen: one date string (an impossible date like 31 February is caught when booking); otherwise empty
    set('date_of_birth', day && month && year ? `${year}-${pad(month)}-${pad(day)}` : '');
    if (!(day && month && year)) delete errors.date_of_birth;
}

const days = Array.from({ length: 31 }, (_, i) => i + 1);
const months = computed(() => Array.from({ length: 12 }, (_, i) => {
    const name = new Intl.DateTimeFormat(locale.value === 'nl' ? 'nl-NL' : 'en-GB', { month: 'long' }).format(new Date(2000, i, 1));
    return { value: String(i + 1), label: name.charAt(0).toUpperCase() + name.slice(1) };
}));
// newest year first: someone who is old enough on the pick-up date is born at least minAge years ago
const years = computed(() => {
    const newest = new Date().getFullYear() - props.minAge;
    return Array.from({ length: newest - 1900 + 1 }, (_, i) => newest - i);
});
const select = (key) => [...input(key), 'bg-white'];
const input = (key) => ['w-full rounded-lg border px-3 py-2 text-sm focus:outline-hidden focus:ring-2',
    errors[key] ? 'border-red-400 focus:border-red-500 focus:ring-red-200' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-200'];
</script>

<template>
    <section ref="root" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200" aria-labelledby="client-title">
        <h2 id="client-title" class="text-base font-semibold">{{ t('checkout.client.title') }}</h2>

        <form class="mt-4 space-y-4" novalidate @submit.prevent="book">
            <div class="grid gap-4 sm:grid-cols-2">
                <div v-for="f in [['first_name', 'given-name'], ['last_name', 'family-name']]" :key="f[0]">
                    <label :for="'c-' + f[0]" class="mb-1 block text-xs font-medium text-slate-500">{{ t('checkout.client.' + f[0]) }}</label>
                    <input :id="'c-' + f[0]" :name="f[0]" type="text" :autocomplete="f[1]" maxlength="100" :class="input(f[0])" :aria-invalid="!!errors[f[0]]" :aria-describedby="errors[f[0]] ? 'e-' + f[0] : null"
                           :value="modelValue[f[0]]" @input="set(f[0], $event.target.value)">
                    <p v-if="errors[f[0]]" :id="'e-' + f[0]" class="mt-1 text-xs text-red-600">{{ errors[f[0]] }}</p>
                </div>
            </div>

            <fieldset class="sm:max-w-sm">
                <legend class="mb-1 text-xs font-medium text-slate-500">{{ t('checkout.client.date_of_birth') }}</legend>
                <div class="grid grid-cols-[4.5rem_1fr_6rem] gap-2">
                    <select name="date_of_birth" autocomplete="bday-day" :aria-label="t('checkout.client.day')" :class="select('date_of_birth')" :aria-invalid="!!errors.date_of_birth"
                            :aria-describedby="errors.date_of_birth ? 'e-date_of_birth' : null" :value="picked.day" @change="setDob('day', $event.target.value)">
                        <option value="">{{ t('checkout.client.day') }}</option>
                        <option v-for="d in days" :key="d" :value="String(d)">{{ d }}</option>
                    </select>
                    <select name="dob_month" autocomplete="bday-month" :aria-label="t('checkout.client.month')" :class="select('date_of_birth')" :aria-invalid="!!errors.date_of_birth"
                            :value="picked.month" @change="setDob('month', $event.target.value)">
                        <option value="">{{ t('checkout.client.month') }}</option>
                        <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                    <select name="dob_year" autocomplete="bday-year" :aria-label="t('checkout.client.year')" :class="select('date_of_birth')" :aria-invalid="!!errors.date_of_birth"
                            :value="picked.year" @change="setDob('year', $event.target.value)">
                        <option value="">{{ t('checkout.client.year') }}</option>
                        <option v-for="y in years" :key="y" :value="String(y)">{{ y }}</option>
                    </select>
                </div>
                <p v-if="errors.date_of_birth" id="e-date_of_birth" class="mt-1 text-xs text-red-600">{{ errors.date_of_birth }}</p>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-[1fr_9rem]">
                <div>
                    <label for="c-street" class="mb-1 block text-xs font-medium text-slate-500">{{ t('checkout.client.street') }}</label>
                    <input id="c-street" name="street" type="text" autocomplete="address-line1" maxlength="120" :class="input('street')" :aria-invalid="!!errors.street"
                           :aria-describedby="errors.street ? 'e-street' : null" :value="modelValue.street" @input="set('street', $event.target.value)">
                    <p v-if="errors.street" id="e-street" class="mt-1 text-xs text-red-600">{{ errors.street }}</p>
                </div>
                <div>
                    <label for="c-house" class="mb-1 block text-xs font-medium text-slate-500">{{ t('checkout.client.house_number') }}</label>
                    <input id="c-house" name="house_number" type="text" autocomplete="off" maxlength="10" :class="input('house_number')" :aria-invalid="!!errors.house_number"
                           :aria-describedby="errors.house_number ? 'e-house_number' : null" :value="modelValue.house_number" @input="set('house_number', $event.target.value)">
                    <p v-if="errors.house_number" id="e-house_number" class="mt-1 text-xs text-red-600">{{ errors.house_number }}</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-[9rem_1fr]">
                <div>
                    <label for="c-postal" class="mb-1 block text-xs font-medium text-slate-500">{{ t('checkout.client.postal_code') }}</label>
                    <input id="c-postal" name="postal_code" type="text" autocomplete="postal-code" maxlength="7" placeholder="1234 AB" :class="input('postal_code')" :aria-invalid="!!errors.postal_code"
                           :aria-describedby="errors.postal_code ? 'e-postal_code' : null" :value="modelValue.postal_code" @input="set('postal_code', $event.target.value)">
                    <p v-if="errors.postal_code" id="e-postal_code" class="mt-1 text-xs text-red-600">{{ errors.postal_code }}</p>
                </div>
                <div>
                    <label for="c-city" class="mb-1 block text-xs font-medium text-slate-500">{{ t('checkout.client.city') }}</label>
                    <input id="c-city" name="city" type="text" autocomplete="address-level2" maxlength="100" :class="input('city')" :aria-invalid="!!errors.city"
                           :aria-describedby="errors.city ? 'e-city' : null" :value="modelValue.city" @input="set('city', $event.target.value)">
                    <p v-if="errors.city" id="e-city" class="mt-1 text-xs text-red-600">{{ errors.city }}</p>
                </div>
            </div>

            <!-- Confirmations -->
            <div class="space-y-3 border-t border-slate-100 pt-4">
                <div v-for="c in ['has_valid_license', 'accept_terms']" :key="c">
                    <label class="flex cursor-pointer items-start gap-3 text-sm">
                        <input :name="c" type="checkbox" class="mt-0.5 size-4 shrink-0 rounded border-slate-300 accent-brand-700" :checked="modelValue[c]" :aria-invalid="!!errors[c]"
                               :aria-describedby="errors[c] ? 'e-' + c : null" @change="set(c, $event.target.checked)">
                        <span>{{ t('checkout.client.' + c) }}</span>
                    </label>
                    <p v-if="errors[c]" :id="'e-' + c" class="mt-1 pl-7 text-xs text-red-600">{{ errors[c] }}</p>
                </div>
            </div>

            <!-- Problems with the rental itself (period, price, ...) -->
            <ul v-if="messages.length" class="list-disc space-y-1 rounded-lg bg-red-50 py-3 pl-8 pr-3 text-sm text-red-700" role="alert">
                <li v-for="(m, i) in messages" :key="i">{{ m }}</li>
            </ul>

            <button :disabled="busy" class="w-full rounded-lg bg-accent-500 px-6 py-3 text-sm font-semibold text-brand-950 transition hover:bg-accent-400 disabled:opacity-60 sm:w-auto">
                {{ busy ? t('checkout.client.checking') : t('checkout.client.book') }}
            </button>
        </form>
    </section>
</template>
