<?php

namespace Database\Seeders;

use App\Models\VehicleCategory;
use Database\Seeders\Concerns\AttachesSeedImage;
use Illuminate\Database\Seeder;

class VehicleCategorySeeder extends Seeder
{
    use AttachesSeedImage;

    /** Example categories with their images; safe to run repeatedly. */
    public function run(): void
    {
        $categories = [
            ['slug' => 'passenger-car', 'nl' => 'Personenauto', 'en' => 'Passenger car'],
            ['slug' => 'minibus', 'nl' => 'Personenbus', 'en' => 'Minibus'],
            ['slug' => 'van', 'nl' => 'Bestelwagen', 'en' => 'Van'],
            ['slug' => 'truck', 'nl' => 'Vrachtwagen', 'en' => 'Truck'],
        ];

        foreach ($categories as $i => $data) {
            $category = VehicleCategory::updateOrCreate(
                ['slug' => $data['slug']],
                ['sort_order' => ($i + 1) * 10, 'is_active' => true],
            );

            foreach (['nl', 'en'] as $locale) {
                $category->setTranslation($locale, ['name' => $data[$locale]]);
            }

            $this->attachSeedImage($category, 'categories');
        }
    }
}
