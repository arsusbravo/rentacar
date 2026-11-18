<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\OpeningHour;
use Database\Seeders\Concerns\AttachesSeedImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BranchSeeder extends Seeder
{
    use AttachesSeedImage;

    /** Example branches (placeholder addresses), safe to run repeatedly. */
    public function run(): void
    {
        $branches = [
            ['name' => 'Rentacar Rotterdam',  'city' => 'Rotterdam', 'address' => 'Stationsplein 1',            'postal_code' => '3013 AJ'],
            ['name' => 'Rentacar Amsterdam',  'city' => 'Amsterdam', 'address' => 'Stationsplein 10',           'postal_code' => '1012 AB'],
            ['name' => 'Rentacar Den Haag',   'city' => 'Den Haag',  'address' => 'Koningin Julianaplein 10',   'postal_code' => '2595 AA'],
        ];

        foreach ($branches as $branch) {
            $model = Branch::updateOrCreate(['name' => $branch['name']], $branch + ['is_active' => true]);

            // database/seeders/images/branches/<city>.jpg, e.g. den-haag.jpg
            $this->attachSeedImage($model, 'branches', Str::slug($branch['city']));

            if (! $model->openingHours()->exists()) {
                $model->syncOpeningHours(OpeningHour::defaults());
            }
        }
    }
}
