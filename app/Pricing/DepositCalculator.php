<?php

namespace App\Pricing;

/**
 * The deposit (borg) of a rental: a refundable security hold, so no VAT and not part of the price.
 *
 *   deposit = max( base deposit of the class, price the client pays incl. VAT + margin )   rounded UP to a multiple of round_to
 *             + own_risk_surcharge   when the client keeps the full own risk (does not lower it)
 *
 * So it is always higher than the price (margin > 0, rounding only goes up), and lowering the own risk
 * lowers the deposit by the surcharge. A rental of leasing_from_days or longer counts as leasing and needs
 * no deposit. Money is in integer cents.
 */
class DepositCalculator
{
    /**
     * @param  int  $baseCents  base deposit of the class (0 when none)
     * @param  int  $priceInclVatCents  total the client pays: rental + own-risk reduction + extras, incl. VAT
     * @param  bool  $keepsOwnRisk  the class has an own risk and the client does not lower it
     * @param  int  $hours  length of the rental in hours
     * @return array{cents: int|null, applicable: bool, reason: string|null, basis: string|null, surcharge_cents: int, leasing_from_days?: int}
     *         basis: which term decided the amount before the surcharge: 'base' or 'price'
     */
    public static function calculate(int $baseCents, int $priceInclVatCents, bool $keepsOwnRisk, int $hours): array
    {
        $leasingFromDays = (int) config('pricing.deposit.leasing_from_days');

        if ($hours >= $leasingFromDays * 24) {
            return ['cents' => null, 'applicable' => false, 'reason' => 'leasing', 'basis' => null, 'surcharge_cents' => 0, 'leasing_from_days' => $leasingFromDays];
        }

        $step = max(1, (int) config('pricing.deposit.round_to') * 100);

        // on a tie the price wins over the base
        $terms = ['price' => $priceInclVatCents + (int) config('pricing.deposit.margin') * 100, 'base' => max(0, $baseCents)];
        $raw = max($terms);

        $surcharge = $keepsOwnRisk ? (int) config('pricing.deposit.own_risk_surcharge') * 100 : 0;

        return [
            'cents' => (int) (ceil($raw / $step) * $step) + $surcharge,
            'applicable' => true,
            'reason' => null,
            'basis' => array_search($raw, $terms, true),
            'surcharge_cents' => $surcharge,
        ];
    }
}
