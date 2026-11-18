<?php

namespace Database\Factories;

use App\Models\VehicleCategory;
use App\Models\VehicleClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleClass>
 */
class VehicleClassFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vehicle_category_id' => VehicleCategory::factory(),
            'slug' => fake()->unique()->slug(2),
            'example_models' => 'VW Polo, Opel Corsa',
            'license_category' => 'B',
            'seats' => 5,
            'doors' => 5,
            'baggage_large' => 1,
            'baggage_small' => 1,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /** Every class is offered with manual transmission and petrol unless set otherwise. */
    public function configure(): static
    {
        return $this->afterCreating(fn (VehicleClass $class) => $class->setTransmissions(['manual'])->setFuelTypes(['petrol']));
    }

    /** Offer these fuel types instead, e.g. ['petrol', 'diesel']. */
    public function withFuelTypes(array $fuelTypes): static
    {
        return $this->afterCreating(fn (VehicleClass $class) => $class->setFuelTypes($fuelTypes));
    }

    /** Offer these transmissions instead, e.g. ['manual', 'automatic']. */
    public function withTransmissions(array $transmissions): static
    {
        return $this->afterCreating(fn (VehicleClass $class) => $class->setTransmissions($transmissions));
    }

    /** Also create Dutch and English names. */
    public function named(string $nl, string $en): static
    {
        return $this->afterCreating(function (VehicleClass $class) use ($nl, $en) {
            $class->setTranslation('nl', ['name' => $nl]);
            $class->setTranslation('en', ['name' => $en]);
        });
    }
}
