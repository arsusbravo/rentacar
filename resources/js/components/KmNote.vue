<script setup>
import { useI18n } from 'vue-i18n';
import { formatCents } from '../money';

// What is included in the kilometres and what happens beyond it. `km` is the quote's km block.
defineProps({ km: { type: Object, required: true } });
const { t, locale } = useI18n();
</script>

<template>
    <p class="text-xs text-slate-500">
        <template v-if="km.total === 0">{{ t('checkout.km.noneIncluded') }}</template>
        <template v-else-if="km.extra > 0">{{ t('checkout.km.noteWithExtra', { included: km.included, extra: km.extra, total: km.total }) }}</template>
        <template v-else>{{ t('checkout.km.noteIncluded', { total: km.total }) }}</template>
        <template v-if="km.price_cents !== null">{{ ' ' + t('checkout.km.beyond', { price: formatCents(km.price_cents, locale) }) }}</template>
    </p>
</template>
