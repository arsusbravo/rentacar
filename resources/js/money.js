// Euro amounts in the current language: "€ 6,00" (nl) / "€6.00" (en).
// Prices are calculated on the server (App\Pricing\QuoteBuilder); this only formats them.
export function formatMoney(amount, locale) {
    return new Intl.NumberFormat(locale === 'nl' ? 'nl-NL' : 'en-GB', { style: 'currency', currency: 'EUR' }).format(amount);
}

/** Same for amounts in cents. */
export const formatCents = (cents, locale) => formatMoney(cents / 100, locale);
