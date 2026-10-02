<?php

namespace Database\Factories;

use App\Enums\AvailabilityStatus;
use App\Models\Availability;
use App\Models\MeetingSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Availability>
 */
class AvailabilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meeting_slot_id' => MeetingSlot::factory(),
            'user_id' => User::factory(),
            'status' => AvailabilityStatus::OnSite,
        ];
    }
}
