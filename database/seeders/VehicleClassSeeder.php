<?php

namespace Database\Seeders;

use App\Models\VehicleCategory;
use App\Models\VehicleClass;
use Database\Seeders\Concerns\AttachesSeedImage;
use Illuminate\Database\Seeder;

class VehicleClassSeeder extends Seeder
{
    use AttachesSeedImage;

    /** Example classes with realistic specs; safe to run repeatedly. Adjust to your own fleet. */
    public function run(): void
    {
        $car = ['category' => 'passenger-car', 'license_category' => 'B', 'doors' => 5];
        $van = ['category' => 'van', 'license_category' => 'B', 'doors' => 4, 'fuel_types' => ['diesel'], 'transmissions' => ['manual']];

        $classes = [
            // --- Personenauto ---------------------------------------------------------------
            $car + ['slug' => 'mini', 'nl' => ['Mini', 'Wendbaar in de stad'], 'en' => ['Mini', 'Nimble in the city'],
                'example_models' => 'Toyota Aygo, Citroën C1, Peugeot 108', 'fuel_types' => ['petrol'], 'transmissions' => ['manual'],
                'seats' => 4, 'baggage_large' => 0, 'baggage_small' => 2, 'cargo_volume_liters' => 231],
            $car + ['slug' => 'compact', 'nl' => ['Compact', 'Handig en zuinig'], 'en' => ['Compact', 'Handy and economical'],
                'example_models' => 'VW Polo, Opel Corsa, Renault Clio', 'fuel_types' => ['petrol', 'diesel'], 'transmissions' => ['manual', 'automatic'],
                'seats' => 5, 'baggage_large' => 1, 'baggage_small' => 1, 'cargo_volume_liters' => 350],
            $car + ['slug' => 'midsize', 'nl' => ['Middenklasse', 'Ruim en comfortabel'], 'en' => ['Midsize', 'Roomy and comfortable'],
                'example_models' => 'Seat Leon, VW Golf, Ford Focus', 'fuel_types' => ['petrol', 'diesel'], 'transmissions' => ['manual', 'automatic'],
                'seats' => 5, 'baggage_large' => 2, 'baggage_small' => 1, 'cargo_volume_liters' => 380],
            $car + ['slug' => 'station-wagon', 'nl' => ['Stationwagen', 'Extra bagageruimte'], 'en' => ['Station wagon', 'Extra luggage space'],
                'example_models' => 'Skoda Octavia Combi, VW Passat Variant, Opel Astra Sports Tourer', 'fuel_types' => ['petrol', 'diesel'], 'transmissions' => ['manual', 'automatic'],
                'seats' => 5, 'baggage_large' => 3, 'baggage_small' => 2, 'cargo_volume_liters' => 600],
            $car + ['slug' => 'suv', 'nl' => ['SUV', 'Hoge zit en veel ruimte'], 'en' => ['SUV', 'High seating and plenty of space'],
                'example_models' => 'Nissan Qashqai, VW Tiguan, Kia Sportage', 'fuel_types' => ['petrol', 'diesel', 'hybrid'], 'transmissions' => ['manual', 'automatic'],
                'seats' => 5, 'baggage_large' => 3, 'baggage_small' => 1, 'cargo_volume_liters' => 480],
            $car + ['slug' => 'electric', 'nl' => ['Elektrisch', 'Rijden zonder uitstoot'], 'en' => ['Electric', 'Zero-emission driving'],
                'example_models' => 'Tesla Model 3, VW ID.3, Renault Mégane E-Tech', 'fuel_types' => ['electric'], 'transmissions' => ['automatic'],
                'seats' => 5, 'baggage_large' => 2, 'baggage_small' => 1, 'cargo_volume_liters' => 400],

            // --- Personenbus ----------------------------------------------------------------
            ['category' => 'minibus', 'slug' => 'mpv-7', 'nl' => ['7-persoons', 'Ruimte voor het hele gezin'], 'en' => ['7-seater', 'Room for the whole family'],
                'example_models' => 'VW Touran, Opel Zafira, Seat Alhambra', 'fuel_types' => ['diesel', 'petrol'], 'transmissions' => ['manual', 'automatic'], 'license_category' => 'B',
                'seats' => 7, 'doors' => 5, 'baggage_large' => 1, 'baggage_small' => 2],
            ['category' => 'minibus', 'slug' => 'minibus-9', 'nl' => ['9-persoons bus', 'Voor groepen tot negen personen'], 'en' => ['9-seater minibus', 'For groups of up to nine'],
                'example_models' => 'VW Caravelle, Ford Tourneo Custom, Mercedes Vito Tourer', 'fuel_types' => ['diesel'], 'transmissions' => ['manual'], 'license_category' => 'B',
                'seats' => 9, 'doors' => 4, 'baggage_large' => 4, 'baggage_small' => 4],

            // --- Bestelwagen ----------------------------------------------------------------
            $van + ['slug' => 'van-small', 'nl' => ['Kleine bestelwagen', 'Voor kleine verhuizingen en klussen'], 'en' => ['Small van', 'For small moves and jobs'],
                'example_models' => 'Citroën Berlingo, VW Caddy Cargo, Peugeot Partner', 'seats' => 2, 'cargo_volume_liters' => 3300, 'payload_kg' => 650],
            $van + ['slug' => 'van-medium', 'nl' => ['Middelgrote bestelwagen', 'De alleskunner'], 'en' => ['Medium van', 'The all-rounder'],
                'example_models' => 'Ford Transit Custom, VW Transporter, Renault Trafic', 'seats' => 3, 'cargo_volume_liters' => 6000, 'payload_kg' => 1000],
            $van + ['slug' => 'van-large', 'nl' => ['Grote bestelwagen', 'Maximaal laadvolume met rijbewijs B'], 'en' => ['Large van', 'Maximum load volume on a B licence'],
                'example_models' => 'Ford Transit L3H2, Mercedes Sprinter, Renault Master', 'seats' => 3, 'cargo_volume_liters' => 11000, 'payload_kg' => 1200],

            // --- Vrachtwagen ----------------------------------------------------------------
            ['category' => 'truck', 'slug' => 'box-truck', 'nl' => ['Verhuiswagen', 'Bakwagen met laadklep (rijbewijs C1)'], 'en' => ['Moving truck', 'Box truck with tail lift (licence C1)'],
                'example_models' => 'Mercedes Atego, DAF LF, Iveco Eurocargo', 'fuel_types' => ['diesel'], 'transmissions' => ['automatic'], 'license_category' => 'C1',
                'seats' => 3, 'doors' => 2, 'cargo_volume_liters' => 28000, 'payload_kg' => 3000],
        ];

        $categories = VehicleCategory::pluck('id', 'slug');

        foreach ($classes as $i => $data) {
            $class = VehicleClass::updateOrCreate(
                ['slug' => $data['slug']],
                collect($data)->only([
                    'example_models', 'license_category', 'seats', 'doors',
                    'baggage_large', 'baggage_small', 'cargo_volume_liters', 'payload_kg',
                ])->all() + [
                    'vehicle_category_id' => $categories[$data['category']],
                    'sort_order' => ($i + 1) * 10,
                    'is_active' => true,
                ],
            );

            $class->setTransmissions($data['transmissions']);
            $class->setFuelTypes($data['fuel_types']);
            $this->attachSeedImage($class, 'classes');

            foreach (['nl', 'en'] as $locale) {
                [$name, $description] = $data[$locale];
                $class->setTranslation($locale, ['name' => $name, 'description' => $description]);
            }
        }
    }
}
