<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { api, ApiError } from '../api';

// Confirms an unconfirmed booking. DEMO: the status changes, but no email is really sent.
const props = defineProps({ booking: { type: Object, required: true } });
const emit = defineEmits(['close', 'confirmed']);
const { t } = useI18n();

const busy = ref(false);
const error = ref('');
const okButton = ref(null);

async function confirm() {
    busy.value = true;
    error.value = '';
    try {
        emit('confirmed', await api.post(`/admin/api/bookings/${props.booking.id}/confirm`));
    } catch (e) {
        if (e instanceof ApiError) error.value = e.message; else throw e;
    } finally {
        busy.value = false;
    }
}

const onKey = (e) => e.key === 'Escape' && emit('close');
onMounted(() => { document.addEventListener('keydown', onKey); okButton.value?.focus(); });
onUnmounted(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
        <div role="alertdialog" aria-modal="true" aria-labelledby="cb-title" class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <h2 id="cb-title" class="text-lg font-semibold">{{ t('admin.confirmDialog.title', { reference: booking.reference }) }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ booking.customer.name }} · {{ booking.vehicle.name }}</p>

            <div class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200" role="note">
                <strong>{{ t('admin.confirmDialog.demoTitle') }}</strong>
                <p class="mt-1">{{ t('admin.confirmDialog.demo', { email: booking.customer.email || '—' }) }}</p>
            </div>

            <p v-if="error" class="mt-3 text-sm text-red-600" role="alert">{{ error }}</p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50" @click="emit('close')">{{ t('common.cancel') }}</button>
                <button ref="okButton" type="button" :disabled="busy" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50" @click="confirm">{{ t('admin.confirmDialog.confirm') }}</button>
            </div>
        </div>
    </div>
</template>
