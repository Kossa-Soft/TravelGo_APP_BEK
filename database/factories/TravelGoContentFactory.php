<?php

namespace Database\Factories;

use App\Models\TravelGoContent;
use App\Support\ContentStatus;
use App\Support\TravelGoType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TravelGoContent> */
class TravelGoContentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => TravelGoType::Country,
            'country_code' => fake()->unique()->countryCode(),
            'name' => ['uk' => fake()->word(), 'en' => fake()->country()],
            'data' => [],
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => ContentStatus::Published, 'published_at' => now()]);
    }
}
