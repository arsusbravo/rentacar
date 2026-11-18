<?php

namespace App\Pricing;

use App\Models\VehicleClassRate;
use Carbon\CarbonInterface;

/**
 * Prices a rental with the cheapest combination of the tariffs of one price set: half day, day, week,
 * month and year. Every unit covers a number of hours; the units together must cover the rental
 * (started hours count), and the cheapest cover wins. Money is in integer cents.
 */
class RentalPriceCalculator
{
    /**
     * @return array{cents: int, hours: int, breakdown: list<array{unit: string, count: int, cents: int}>}|null
     *         null when the price set has no time-based tariff
     */
    public static function calculate(VehicleClassRate $rate, CarbonInterface $pickup, CarbonInterface $return): ?array
    {
        $units = self::units($rate);

        if ($units === []) {
            return null;
        }

        $hours = self::hours($pickup, $return);

        // best[h] = cheapest cost to cover h hours; units[..] = how many hours each one covers.
        $cost = array_fill(0, $hours + 1, PHP_INT_MAX);
        $count = array_fill(0, $hours + 1, PHP_INT_MAX);
        $choice = array_fill(0, $hours + 1, null);
        $cost[0] = 0;
        $count[0] = 0;

        for ($h = 1; $h <= $hours; $h++) {
            foreach ($units as $unit => [$unitHours, $unitCents]) {
                $previous = max(0, $h - $unitHours);
                $candidate = $cost[$previous] + $unitCents;
                $pieces = $count[$previous] + 1;

                // cheapest; on a tie, fewest units (e.g. one week instead of seven days at the same price)
                if ($candidate < $cost[$h] || ($candidate === $cost[$h] && $pieces < $count[$h])) {
                    $cost[$h] = $candidate;
                    $count[$h] = $pieces;
                    $choice[$h] = [$unit, $previous];
                }
            }
        }

        // Walk back through the choices to see which units were used.
        $used = [];
        for ($h = $hours; $h > 0; $h = $choice[$h][1]) {
            $used[$choice[$h][0]] = ($used[$choice[$h][0]] ?? 0) + 1;
        }

        // Longest unit first.
        uksort($used, fn (string $a, string $b) => $units[$b][0] <=> $units[$a][0]);

        return [
            'cents' => $cost[$hours],
            'hours' => $hours,
            'breakdown' => array_map(
                fn (string $unit, int $times) => ['unit' => $unit, 'count' => $times, 'cents' => $units[$unit][1] * $times],
                array_keys($used),
                $used,
            ),
        ];
    }

    /** Rental length in whole hours: every started hour counts, at least one. */
    public static function hours(CarbonInterface $pickup, CarbonInterface $return): int
    {
        return max(1, (int) ceil(($return->getTimestamp() - $pickup->getTimestamp()) / 3600));
    }

    /** @return array<string, array{0: int, 1: int}> unit => [hours covered, price in cents] for the tariffs that exist */
    private static function units(VehicleClassRate $rate): array
    {
        $hours = [
            'price_half_day' => ['half_day', (int) config('pricing.half_day_hours')],
            'price_day' => ['day', 24],
            'price_week' => ['week', 7 * 24],
            'price_month' => ['month', (int) config('pricing.month_days') * 24],
            'price_year' => ['year', (int) config('pricing.year_days') * 24],
        ];

        $units = [];
        foreach ($hours as $column => [$name, $unitHours]) {
            $cents = $rate->cents($column);

            if ($cents !== null) {
                $units[$name] = [$unitHours, $cents];
            }
        }

        return $units;
    }
}
