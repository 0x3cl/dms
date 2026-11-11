<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => ucwords(fake()->unique()->words(2, true)),
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
