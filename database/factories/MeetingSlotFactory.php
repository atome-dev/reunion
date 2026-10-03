<?php

namespace Database\Factories;

use App\Models\Meeting;
use App\Models\MeetingSlot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<MeetingSlot>
 */
class MeetingSlotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'starts_at' => now()->addDays(fake()->numberBetween(1, 30))->setTime(18, 30),
            'ends_at' => fn (array $attributes) => Carbon::parse($attributes['starts_at'])->addHours(2),
        ];
    }
}
