<?php

namespace Database\Factories;

use App\Models\AvailabilityDay;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityDay>
 */
class AvailabilityDayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'day' => fn () => now(config('app.display_timezone'))->addDay()->toDateString(),
            'cells' => AvailabilityDay::Empty,
        ];
    }

    public function cells(string $cells): static
    {
        return $this->state(fn (array $attributes) => ['cells' => $cells]);
    }
}
