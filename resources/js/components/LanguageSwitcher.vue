<script setup>
import { useI18n } from 'vue-i18n';
import { setLocale } from '../i18n';

const props = defineProps({ dark: Boolean });
const { locale, t } = useI18n();
const options = [{ code: 'nl', label: 'NL' }, { code: 'en', label: 'EN' }];
</script>

<template>
    <div class="inline-flex overflow-hidden rounded-lg text-xs font-semibold ring-1" :class="props.dark ? 'ring-slate-600' : 'ring-slate-300'" role="group" :aria-label="t('common.language')">
        <button v-for="o in options" :key="o.code" type="button" @click="setLocale(o.code)"
                :aria-pressed="locale === o.code"
                class="px-2.5 py-1"
                :class="locale === o.code
                    ? 'bg-brand-700 text-white'
                    : (props.dark ? 'text-brand-200 hover:bg-brand-900' : 'text-slate-600 hover:bg-slate-100')">
            {{ o.label }}
        </button>
    </div>
</template>
