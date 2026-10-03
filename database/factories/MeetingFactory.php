<?php

namespace Database\Factories;

use App\Enums\MeetingStatus;
use App\Models\Group;
use App\Models\Meeting;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meeting>
 */
class MeetingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'title' => fake()->sentence(3),
            'created_by' => fn (array $attributes) => Group::find($attributes['group_id'])->owner_id,
            'range_start' => today()->addDay()->toDateString(),
            'range_end' => today()->addDays(14)->toDateString(),
            'deadline' => today()->addDays(7)->toDateString(),
            'status' => MeetingStatus::Collecting,
        ];
    }

    public function voting(): static
    {
        return $this->state(fn (array $attributes) => ['status' => MeetingStatus::Voting]);
    }

    public function confirmed(?CarbonInterface $startsAt = null): static
    {
        $startsAt ??= now()->addWeek()->setTime(18, 0);

        return $this->state(fn (array $attributes) => [
            'status' => MeetingStatus::Confirmed,
            'confirmed_starts_at' => $startsAt,
            'confirmed_ends_at' => $startsAt->copy()->addHours(2),
        ]);
    }
}
