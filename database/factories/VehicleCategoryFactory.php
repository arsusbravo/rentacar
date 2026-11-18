<?php

namespace Database\Factories;

use App\Models\VehicleCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleCategory>
 */
class VehicleCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /** Also create Dutch and English names. */
    public function named(string $nl, string $en): static
    {
        return $this->afterCreating(function (VehicleCategory $category) use ($nl, $en) {
            $category->setTranslation('nl', ['name' => $nl]);
            $category->setTranslation('en', ['name' => $en]);
        });
    }
}
