<script setup>
import { useI18n } from 'vue-i18n';

// Vehicle type as selectable cards (radio buttons underneath, so keyboard and screen readers work).
// Each category may have an icon (a transparent PNG silhouette): it is tinted with a CSS mask,
// grey by default and brand blue when selected.
defineProps({
    modelValue: { type: String, default: '' },
    categories: { type: Array, default: () => [] }, // { slug, name, icon }
    // lg: big cards (icon above the name) | md: 2x2 tiles, icon beside the name | sm: slim chips in one row
    size: { type: String, default: 'lg' },
});
defineEmits(['update:modelValue']);

const { t } = useI18n();

const mask = (url) => ({ maskImage: `url(${url})`, WebkitMaskImage: `url(${url})` });
</script>

<template>
    <fieldset>
        <legend class="text-xs font-medium text-slate-500" :class="size === 'sm' ? 'sr-only' : 'mb-1.5'">{{ t('checkout.category') }}</legend>

        <div role="radiogroup"
             :class="{ 'grid grid-cols-2 gap-3 sm:grid-cols-4': size === 'lg', 'grid grid-cols-4 gap-2 sm:grid-cols-2': size === 'md', 'flex flex-wrap gap-2 max-sm:gap-1.5': size === 'sm' }">
            <label v-for="c in categories" :key="c.slug" :title="c.name"
                   class="group relative cursor-pointer border border-slate-200 bg-white shadow-xs transition duration-200 hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-md has-checked:border-brand-700 has-checked:bg-brand-50 has-checked:shadow-md has-focus-visible:ring-2 has-focus-visible:ring-brand-500"
                   :class="{
                       'flex flex-col items-center gap-1 rounded-xl px-3 pb-3 pt-2': size === 'lg',
                       'flex items-center justify-center gap-1.5 rounded-xl px-2 py-1.5 sm:justify-start': size === 'md',
                       'flex items-center gap-2 rounded-lg px-3 py-1.5 max-sm:px-2.5': size === 'sm',
                   }">
                <input type="radio" name="category" class="sr-only" :value="c.slug" :aria-label="c.name" :checked="modelValue === c.slug" @change="$emit('update:modelValue', c.slug)">

                <span v-if="c.icon" aria-hidden="true"
                      class="block shrink-0 bg-slate-500 transition-colors duration-200 group-hover:bg-brand-500 group-has-checked:bg-brand-700"
                      :class="{ 'h-14 w-28': size === 'lg', 'h-8 w-12': size === 'md', 'h-5 w-9': size === 'sm' }"
                      :style="{ ...mask(c.icon), maskRepeat: 'no-repeat', WebkitMaskRepeat: 'no-repeat', maskPosition: 'center', WebkitMaskPosition: 'center', maskSize: '112% auto', WebkitMaskSize: '112% auto' }"></span>

                <!-- slim chips (search bar) and the homepage tiles show the icon only on a phone; the name is the tooltip and the accessible label -->
                <span class="font-medium leading-tight text-slate-700 transition-colors group-has-checked:text-brand-800"
                      :class="[size === 'md' ? 'text-xs' : 'text-sm', size !== 'lg' ? 'max-sm:sr-only' : '']">{{ c.name }}</span>

                <!-- check mark on the selected card -->
                <svg class="absolute scale-50 rounded-full bg-white text-brand-700 opacity-0 transition duration-200 group-has-checked:scale-100 group-has-checked:opacity-100"
                     :class="size === 'sm' ? '-right-1.5 -top-1.5 size-4' : 'right-1.5 top-1.5 size-4'"
                     viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.7-9.3a1 1 0 0 0-1.4-1.4L9 10.6 7.7 9.3a1 1 0 0 0-1.4 1.4l2 2a1 1 0 0 0 1.4 0l4-4Z" clip-rule="evenodd"/>
                </svg>
            </label>
        </div>
    </fieldset>
</template>
