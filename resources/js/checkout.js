// Rental checkout: Vue. Mounted into #checkout-app by rent.blade.php, after a vehicle class was selected.
import '../css/app.css';
import { createApp } from 'vue';
import Checkout from './pages/Checkout.vue';
import i18n from './i18n';

const el = document.getElementById('checkout-app');

createApp(Checkout, {
    branches: JSON.parse(el.dataset.branches),
    initial: JSON.parse(el.dataset.initial),
    vehicleClass: JSON.parse(el.dataset.vehicleClass),
    extras: JSON.parse(el.dataset.extras),
    vehiclesUrl: el.dataset.vehiclesUrl,
    minAge: Number(el.dataset.minAge) || 18,
}).use(i18n).mount(el);
