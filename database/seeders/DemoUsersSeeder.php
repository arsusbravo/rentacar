<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The accounts of the demo. The two demo logins are shown on the login page, so their password is
 * (re)set on every run; the other accounts only get a password when they are created, so a password
 * somebody changed is never overwritten.
 */
class DemoUsersSeeder extends Seeder
{
    /** Shown on the login page (resources/views/auth/login.blade.php). */
    public const DEMO = [
        ['name' => 'Demo admin',    'email' => 'admin@rentacar.test',    'role' => Role::Admin,    'password' => 'admindemo123'],
        ['name' => 'Demo employee', 'email' => 'employee@rentacar.test', 'role' => Role::Employee, 'password' => 'employeedemo'],
    ];

    public function run(): void
    {
        foreach (self::DEMO as $user) {
            User::updateOrCreate(['email' => $user['email']], $user + ['email_verified_at' => now()]);
        }

        // Default passwords are for local development only: change them before deploying.
        foreach ([
            ['name' => 'Admin',  'email' => 'info@arsus.nl',       'role' => Role::Admin],
            ['name' => 'Client', 'email' => 'client@rentacar.test', 'role' => Role::Client],
        ] as $user) {
            User::firstOrCreate(['email' => $user['email']], $user + ['password' => 'password', 'email_verified_at' => now()]);
        }
    }
}
