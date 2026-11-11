<?php

namespace Database\Factories;

use App\Models\Division;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Section>
 */
class SectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'division_id' => Division::factory(),
            'code' => strtoupper(fake()->unique()->bothify('SEC-##')),
            'name' => fake()->unique()->words(2, true).' Section',
        ];
    }
}
