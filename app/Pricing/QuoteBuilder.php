<?php

namespace App\Pricing;

use App\Models\Extra;
use App\Models\VehicleClass;
use Carbon\CarbonInterface;

/**
 * The price build-up of a rental: rental price, optional own-risk reduction, extras, VAT and total.
 * All amounts are integer cents; prices in the database exclude VAT, VAT is added here.
 */
class QuoteBuilder
{
    /**
     * @param  list<array{id: int|string, quantity?: int|string}>  $extras  wanted extras
     * @return array<string, mixed>|null  null when the class has no price for the fuel/transmission
     */
    public function build(
        VehicleClass $class,
        string $fuel,
        string $transmission,
        CarbonInterface $pickup,
        CarbonInterface $return,
        array $extras = [],
        bool $reduceOwnRisk = false,
        int $extraKm = 0,
    ): ?array {
        $class->loadMissing(['rates', 'fuelOptions', 'transmissionOptions']);

        $rate = $class->rateFor($fuel, $transmission);
        $rental = $rate ? RentalPriceCalculator::calculate($rate, $pickup, $return) : null;

        if ($rental === null) {
            return null;
        }

        $days = Extra::rentalDays($pickup, $return);

        // Own-risk reduction: a price per rental day, only when the class offers it.
        $reduction = $reduceOwnRisk && $class->ownRiskReductionOffered()
            ? $class->ownRiskReductionPriceCents() * $days
            : null;

        // Only active extras that are coupled to this class; the quantity is capped by the extra.
        $wanted = collect($extras)->mapWithKeys(fn ($e) => [(int) ($e['id'] ?? 0) => max(1, (int) ($e['quantity'] ?? 1))]);
        $extraLines = Extra::active()->availableFor($class)->ordered()->with('translations')
            ->whereKey($wanted->keys()->all())->get()
            ->map(fn (Extra $extra) => [
                'id' => $extra->id,
                'title' => $extra->title,
                'price_unit' => $extra->price_unit,
                'quantity' => $extra->price_unit === 'per_unit' ? min($wanted[$extra->id], $extra->max_quantity) : 1,
                'cents' => $extra->totalCents($days, $wanted[$extra->id]),
            ])->values()->all();

        // Kilometres: a flat number is included; the client may book extra ones at the per-km price of the rate.
        // Driving more than included + extra is settled after the return at that same price.
        $kmPriceCents = $rate->cents('price_km');
        $extraKmAvailable = $kmPriceCents !== null;
        $extraKm = $extraKmAvailable ? max(0, min($extraKm, (int) config('pricing.km.max_extra'))) : 0;
        $extraKmCents = $extraKm * ($kmPriceCents ?? 0);

        $subtotal = $rental['cents'] + ($reduction ?? 0) + array_sum(array_column($extraLines, 'cents')) + $extraKmCents;
        $vat = (int) round($subtotal * config('pricing.vat_rate') / 100);

        // Deposit: above what the client pays (incl. VAT), plus a surcharge while the full own risk is kept.
        $deposit = DepositCalculator::calculate(
            $class->depositCents() ?? 0,
            $subtotal + $vat,
            ($class->ownRiskCents() ?? 0) > 0 && $reduction === null,
            $rental['hours'],
        );

        return [
            'days' => $days,
            'rental' => $rental,
            'own_risk' => [
                'cents' => $class->ownRiskCents(),
                'reduction_offered' => $class->ownRiskReductionOffered(),
                'reduced_cents' => $class->ownRiskReducedCents(),
                'reduction_price_cents_per_day' => $class->ownRiskReductionPriceCents(),
                'reduction_chosen' => $reduction !== null,
                'reduction_cents' => $reduction,
            ],
            'extras' => $extraLines,
            'km' => [
                'included' => (int) $class->km_included,
                'extra' => $extraKm,
                'extra_cents' => $extraKmCents,
                'total' => (int) $class->km_included + $extraKm,
                'price_cents' => $kmPriceCents,       // per kilometre, for kilometres beyond the total
                'extra_available' => $extraKmAvailable,
            ],
            'subtotal_cents' => $subtotal,
            'vat_rate' => (float) config('pricing.vat_rate'),
            'vat_cents' => $vat,
            'total_cents' => $subtotal + $vat,
            // refundable security hold: not part of the subtotal, VAT or total
            'deposit' => $deposit,
        ];
    }
}
