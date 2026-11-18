// Homepage search: the same form as on the vehicles page, in a compact card.
// The rest of the homepage is plain Blade.
import '../css/app.css';
import { createApp } from 'vue';
import RentalDetailsForm from './components/RentalDetailsForm.vue';
import i18n from './i18n';

const el = document.getElementById('home-search');

createApp(RentalDetailsForm, {
    branches: JSON.parse(el.dataset.branches),
    categories: JSON.parse(el.dataset.categories),
    compact: true,
    redirectTo: el.dataset.vehiclesUrl, // after searching the client chooses a vehicle
}).use(i18n).mount(el);
