// Vehicles page: the search form (prefilled) above the Blade-rendered vehicle choices.
import '../css/app.css';
import { createApp } from 'vue';
import RentalDetailsForm from './components/RentalDetailsForm.vue';
import i18n from './i18n';

const el = document.getElementById('vehicles-search');
const SEARCH_KEYS = ['pickup', 'return', 'pickup_date', 'pickup_time', 'return_date', 'return_time'];

// The "Select" links are plain Blade links. Whatever is filled in the search form right now (also when
// "Search" was not pressed) is put in their address, so the checkout opens with those values. Because the
// address itself is updated, every kind of click works: normal, new tab, copy link.
function syncSelectLinks(query) {
    document.querySelectorAll('a[data-select-class]').forEach((link) => {
        const url = new URL(link.href, window.location.origin);
        SEARCH_KEYS.forEach((key) => url.searchParams.delete(key));
        Object.entries(query).forEach(([key, value]) => url.searchParams.set(key, value));
        link.href = url.pathname + url.search;
    });
}

createApp(RentalDetailsForm, {
    branches: JSON.parse(el.dataset.branches),
    categories: JSON.parse(el.dataset.categories),
    initial: JSON.parse(el.dataset.initial),
    redirectTo: el.dataset.vehiclesUrl, // searching again reloads this page with the new choices
    search: true,
    bar: true, // low search bar: the vehicles are the main content of this page
    collapsible: true, // a phone folds it into a summary once everything is filled in
    onQuery: syncSelectLinks,
}).use(i18n).mount(el);
