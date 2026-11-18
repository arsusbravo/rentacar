<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Safe to run repeatedly.
     */
    public function run(): void
    {
        // Default passwords are for local development only: change them before deploying.
        $users = [
            ['name' => 'Admin',    'email' => 'info@arsus.nl',         'role' => Role::Admin],
            ['name' => 'Employee', 'email' => 'employee@rentacar.test', 'role' => Role::Employee],
            ['name' => 'Client',   'email' => 'client@rentacar.test',   'role' => Role::Client],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                $user + ['password' => 'password', 'email_verified_at' => now()],
            );
        }

        $this->call([
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
