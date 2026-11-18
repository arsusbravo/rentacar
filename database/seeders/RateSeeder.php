<?php

namespace Database\Seeders;

use App\Models\VehicleClass;
use App\Models\VehicleClassRate;
use Illuminate\Database\Seeder;

class RateSeeder extends Seeder
{
    /**
     * Example prices (excluding VAT) for every class x fuel type x transmission, and the own risk per class.
     * Made-up numbers: adjust them in the admin. Re-running overwrites prices edited there.
     */
    public function run(): void
    {
        // slug => [day price (petrol, manual), per km, own risk, own risk after reduction, reduction price per day]
        $classes = [
            'mini' => [29, 0.15, 500, 250, 6],
            'compact' => [35, 0.18, 750, 250, 7],
            'midsize' => [45, 0.20, 1000, 250, 8],
            'station-wagon' => [55, 0.22, 1000, 250, 9],
            'suv' => [65, 0.25, 1250, 250, 11],
            'electric' => [60, 0.22, 1250, 250, 12],
            'mpv-7' => [85, 0.28, 1250, 250, 12],
            'minibus-9' => [105, 0.32, 1500, 250, 15],
            'van-small' => [49, 0.25, 1000, 250, 10],
            'van-medium' => [65, 0.28, 1250, 250, 12],
            'van-large' => [85, 0.32, 1500, 250, 14],
            'box-truck' => [120, 0.40, 1500, 500, 18],
        ];

        $fuelFactor = ['petrol' => 1.0, 'diesel' => 1.10, 'hybrid' => 1.15, 'plugin_hybrid' => 1.20, 'electric' => 1.25, 'lpg' => 0.95];
        $automaticPerDay = 5;
        $half = fn (float $x) => round($x * 2) / 2; // to the nearest 0.50

        foreach (VehicleClass::with(['fuelOptions', 'transmissionOptions'])->get() as $class) {
            if (! isset($classes[$class->slug])) {
                continue;
            }

            [$base, $perKm, $ownRisk, $reduced, $reductionPrice] = $classes[$class->slug];

            $class->update([
                'own_risk' => $ownRisk,
                'own_risk_reduced' => $reduced,
                'own_risk_reduction_price' => $reductionPrice,
            ]);

            foreach ($class->combinations() as $combination) {
                $day = $half($base * ($fuelFactor[$combination['fuel_type']] ?? 1) + ($combination['transmission'] === 'automatic' ? $automaticPerDay : 0));

                VehicleClassRate::updateOrCreate(
                    ['vehicle_class_id' => $class->id] + $combination,
                    [
                        'price_half_day' => $half($day * 0.65),
                        'price_day' => $day,
                        'price_week' => $day * 6,
                        'price_month' => $day * 20,
                        'price_year' => $day * 200,
                        'price_km' => $perKm,
                    ],
                );
            }
        }
    }
}
