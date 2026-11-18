<?php

namespace Database\Seeders;

use App\Models\VehicleClass;
use Illuminate\Database\Seeder;

class DepositSeeder extends Seeder
{
    /**
     * Example base deposits (borg) per class, in EUR. Made-up numbers: adjust them in the admin.
     * Only classes WITHOUT a deposit are filled, so running this never overwrites what was entered.
     */
    public function run(): void
    {
        // Small minimums: for most rentals the price + EUR 150 decides, these only matter for very cheap rentals.
        $deposits = [
            'mini' => 100, 'compact' => 150, 'midsize' => 200, 'station-wagon' => 250, 'suv' => 300, 'electric' => 300,
            'mpv-7' => 300, 'minibus-9' => 400,
            'van-small' => 200, 'van-medium' => 250, 'van-large' => 300,
            'box-truck' => 500,
        ];

        foreach ($deposits as $slug => $amount) {
            VehicleClass::where('slug', $slug)->whereNull('deposit')->update(['deposit' => $amount]);
        }
    }
}
