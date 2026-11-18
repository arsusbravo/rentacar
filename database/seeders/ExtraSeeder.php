<?php

namespace Database\Seeders;

use App\Models\Extra;
use App\Models\VehicleClass;
use Illuminate\Database\Seeder;

class ExtraSeeder extends Seeder
{
    /**
     * Example extras with example prices, coupled to the classes they are offered for
     * (adjust in the admin); safe to run repeatedly. 'classes' => '*' couples an extra to every class.
     */
    public function run(): void
    {
        $extras = [
            [
                'slug' => 'winter-tyres', 'price' => 6.00, 'price_unit' => 'per_day', 'classes' => ['mini', 'compact', 'midsize', 'station-wagon', 'suv', 'electric', 'mpv-7', 'minibus-9', 'van-small', 'van-medium', 'van-large'],
                'nl' => ['Winterbanden', 'Winterbanden voor extra grip bij kou, regen en sneeuw.'],
                'en' => ['Winter tyres', 'Winter tyres for extra grip in cold, rain and snow.'],
            ],
            [
                'slug' => 'navigation', 'price' => 5.00, 'price_unit' => 'per_day', 'classes' => '*',
                'nl' => ['Navigatiesysteem', 'Navigatie met actuele kaarten en verkeersinformatie.'],
                'en' => ['Navigation system', 'Navigation with up-to-date maps and traffic information.'],
            ],
            [
                'slug' => 'towbar', 'price' => 30.00, 'price_unit' => 'per_time', 'classes' => ['midsize', 'station-wagon', 'suv', 'mpv-7', 'van-small', 'van-medium', 'van-large'],
                'nl' => ['Trekhaak', 'Afneembare trekhaak, geschikt voor aanhangers en fietsendragers. De aanhanger is niet inbegrepen.'],
                'en' => ['Tow bar', 'Detachable tow bar for trailers and bike carriers. The trailer is not included.'],
            ],
            [
                'slug' => 'child-seat', 'price' => 12.50, 'price_unit' => 'per_unit', 'max_quantity' => 3, 'classes' => ['mini', 'compact', 'midsize', 'station-wagon', 'suv', 'electric', 'mpv-7', 'minibus-9'],
                'nl' => ['Kinderzitje', 'Kinderzitje voor kinderen van 9 tot 36 kg. Kies het aantal dat je nodig hebt.'],
                'en' => ['Child seat', 'Child seat for children of 9 to 36 kg. Choose how many you need.'],
            ],
            [
                'slug' => 'additional-driver', 'price' => 25.00, 'price_unit' => 'per_time', 'classes' => '*',
                'nl' => ['Extra bestuurder', 'Voeg een extra bestuurder toe aan de huurovereenkomst.'],
                'en' => ['Additional driver', 'Add an additional driver to the rental agreement.'],
            ],
        ];

        $classIds = VehicleClass::pluck('id', 'slug');

        foreach ($extras as $i => $data) {
            $extra = Extra::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'price' => $data['price'],
                    'price_unit' => $data['price_unit'],
                    'max_quantity' => $data['max_quantity'] ?? 1,
                    'sort_order' => ($i + 1) * 10,
                    'is_active' => true,
                ],
            );

            // Couple the extra to its classes (only those that exist).
            $slugs = $data['classes'] === '*' ? $classIds->keys() : collect($data['classes']);
            $extra->classes()->sync($slugs->map(fn ($slug) => $classIds[$slug] ?? null)->filter()->all());

            foreach (['nl', 'en'] as $locale) {
                [$title, $description] = $data[$locale];
                $extra->setTranslation($locale, ['title' => $title, 'description' => $description]);
            }
        }
    }
}
