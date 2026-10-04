<?php

namespace Database\Factories;

use App\Models\Meeting;
use App\Models\MeetingDecline;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingDecline>
 */
class MeetingDeclineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory()->confirmed(),
            'user_id' => User::factory(),
        ];
    }
}
