<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Safe to run repeatedly.
     */
    public function run(): void
    {
        $this->call([
            DemoUsersSeeder::class,
            BranchSeeder::class,
            VehicleCategorySeeder::class,
            VehicleClassSeeder::class,
            ExtraSeeder::class,
            RateSeeder::class,
            DepositSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
