<?php

namespace Database\Factories;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'owner_id' => User::factory(),
        ];
    }

    public function openToMembers(): static
    {
        return $this->state(fn (array $attributes) => [
            'members_can_request_meetings' => true,
            'members_can_invite' => true,
            'members_can_validate' => true,
        ]);
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Group $group) => $group->addMember($group->owner, GroupRole::Organizer));
    }
}
