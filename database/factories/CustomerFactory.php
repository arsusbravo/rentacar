<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-70 years', '-20 years')->format('Y-m-d'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '06 '.fake()->numerify('########'),
            'street' => fake()->streetName(),
            'house_number' => (string) fake()->numberBetween(1, 150),
            'postal_code' => fake()->numerify('####').' '.strtoupper(fake()->lexify('??')),
            'city' => fake()->city(),
        ];
    }
}
