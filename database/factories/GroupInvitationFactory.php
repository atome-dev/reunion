<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GroupInvitation>
 */
class GroupInvitationFactory extends Factory
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
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(40)),
            'invited_by' => fn (array $attributes) => Group::find($attributes['group_id'])->owner_id,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subMinute()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['accepted_at' => now()]);
    }
}
