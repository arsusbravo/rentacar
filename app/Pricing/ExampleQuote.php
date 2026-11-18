<?php

namespace App\Pricing;

use App\Models\Extra;
use App\Models\VehicleClassRate;
use Illuminate\Support\Carbon;

/**
 * A worked example for the homepage ("how is my price calculated"). The numbers are made up but are
 * produced by the real calculators and settings, so the explanation can never drift from the system.
 */
class ExampleQuote
{
    public const DAY_PRICE = 50;     // EUR excl. VAT
    public const WEEK_PRICE = 250;
    public const EXTRA_PER_DAY = 6;  // e.g. winter tyres
    public const BASE_DEPOSIT = 300; // base deposit of the example class
    public const DAYS = 9;

    /** @return array<string, mixed> amounts in cents */
    public static function make(): array
    {
        $pickup = Carbon::parse('2030-01-02 10:00');
        $return = $pickup->copy()->addDays(self::DAYS);

        $rental = RentalPriceCalculator::calculate(
            new VehicleClassRate(['price_day' => self::DAY_PRICE, 'price_week' => self::WEEK_PRICE]),
            $pickup,
            $return,
        );

        $days = Extra::rentalDays($pickup, $return);
        $extra = (new Extra(['price' => self::EXTRA_PER_DAY, 'price_unit' => 'per_day', 'max_quantity' => 1]))->totalCents($days);

        $subtotal = $rental['cents'] + $extra;
        $vat = (int) round($subtotal * config('pricing.vat_rate') / 100);
        $total = $subtotal + $vat;

        $kept = DepositCalculator::calculate(self::BASE_DEPOSIT * 100, $total, true, $rental['hours']);
        $lowered = DepositCalculator::calculate(self::BASE_DEPOSIT * 100, $total, false, $rental['hours']);

        return [
            'days' => $days,
            'breakdown' => $rental['breakdown'],
            'rental_cents' => $rental['cents'],
            'extra_cents' => $extra,
            'subtotal_cents' => $subtotal,
            'vat_cents' => $vat,
            'total_cents' => $total,
            'price_plus_margin_cents' => $total + (int) config('pricing.deposit.margin') * 100,
            'deposit_cents' => $lowered['cents'],          // own risk lowered: no surcharge
            'deposit_with_own_risk_cents' => $kept['cents'], // full own risk kept: + surcharge
        ];
    }
}
