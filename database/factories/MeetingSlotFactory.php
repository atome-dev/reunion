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
    /** Increasing day offset, so two slots of one meeting never share a start. */
    private static int $dayOffset = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'starts_at' => now()->addDays(++self::$dayOffset)->setTime(18, 30),
            'ends_at' => fn (array $attributes) => Carbon::parse($attributes['starts_at'])->addHours(2),
        ];
    }
}
