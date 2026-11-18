<script setup>
import { useI18n } from 'vue-i18n';
import { formatMoney } from '../money';

// The extras (winter tyres, navigation, tow bar, ...) the client can add. Selected: { [extraId]: quantity }.
const props = defineProps({
    extras: { type: Array, default: () => [] }, // { id, title, description, price, price_unit, max_quantity }
    modelValue: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);

const { t, locale } = useI18n();

const isSelected = (e) => props.modelValue[e.id] > 0;

function toggle(e) {
    const next = { ...props.modelValue };
    if (isSelected(e)) delete next[e.id];
    else next[e.id] = 1;
    emit('update:modelValue', next);
}

function setQuantity(e, quantity) {
    emit('update:modelValue', { ...props.modelValue, [e.id]: Math.min(e.max_quantity, Math.max(1, quantity)) });
}
</script>

<template>
    <section v-if="extras.length" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200" aria-labelledby="extras-title">
        <h2 id="extras-title" class="text-base font-semibold">{{ t('checkout.extras.title') }}</h2>
        <p class="mt-0.5 text-xs text-slate-500">{{ t('checkout.extras.subtitle') }}</p>

        <ul class="mt-4 grid gap-3 sm:grid-cols-2">
            <li v-for="e in extras" :key="e.id"
                class="flex flex-col rounded-xl border p-4 transition duration-200"
                :class="isSelected(e) ? 'border-brand-700 bg-brand-50 shadow-sm' : 'border-slate-200 hover:border-brand-300'">
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" class="mt-0.5 size-4 shrink-0 rounded border-slate-300 accent-brand-700" :checked="isSelected(e)" @change="toggle(e)">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium leading-snug">{{ e.title }}</span>
                        <span v-if="e.description" class="mt-0.5 block text-xs text-slate-500">{{ e.description }}</span>
                    </span>
                </label>

                <div class="mt-3 flex items-center justify-between gap-3 pl-7">
                    <p class="text-sm">
                        <span class="font-semibold text-brand-800">{{ formatMoney(e.price, locale) }}</span>
                        <span class="ml-1 text-slate-500">{{ t('checkout.units.' + e.price_unit) }}</span>
                    </p>

                    <!-- quantity, only for extras charged per unit -->
                    <div v-if="e.price_unit === 'per_unit' && e.max_quantity > 1 && isSelected(e)" class="inline-flex items-center overflow-hidden rounded-lg ring-1 ring-slate-300" role="group" :aria-label="t('checkout.extras.quantity')">
                        <button type="button" class="px-2.5 py-1 text-slate-600 hover:bg-white disabled:opacity-40" :disabled="modelValue[e.id] <= 1" :aria-label="t('checkout.extras.less')" @click="setQuantity(e, modelValue[e.id] - 1)">−</button>
                        <span class="min-w-6 text-center text-sm font-medium" aria-live="polite">{{ modelValue[e.id] }}</span>
                        <button type="button" class="px-2.5 py-1 text-slate-600 hover:bg-white disabled:opacity-40" :disabled="modelValue[e.id] >= e.max_quantity" :aria-label="t('checkout.extras.more')" @click="setQuantity(e, modelValue[e.id] + 1)">+</button>
                    </div>
                </div>
            </li>
        </ul>
    </section>
</template>
