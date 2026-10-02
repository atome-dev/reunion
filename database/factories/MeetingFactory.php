<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Meeting;
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
        ];
    }
}
