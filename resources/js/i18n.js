import { createI18n } from 'vue-i18n';
import nl from './locales/nl.json';
import en from './locales/en.json';

// Set by the server: <html lang="nl"> and <meta name="locales" content="nl,en">.
const available = document.querySelector('meta[name="locales"]')?.content.split(',') ?? ['nl', 'en'];
const current = document.documentElement.lang;
const initial = available.includes(current) ? current : 'nl';

const i18n = createI18n({
    legacy: false,
    locale: initial,
    fallbackLocale: 'nl',
    messages: { nl, en },
});

// Persist in a cookie so the server (e.g. the Blade login page) uses the same language.
export function setLocale(locale) {
    if (!available.includes(locale)) return;
    i18n.global.locale.value = locale;
    document.documentElement.lang = locale;
    document.cookie = `locale=${locale}; path=/; max-age=31536000; SameSite=Lax`;
}

export default i18n;
