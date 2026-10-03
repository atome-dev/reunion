<?php

use App\Models\AvailabilityDay;
use App\Models\Meeting;
use Database\Seeders\DemoSeeder;

test('the demo seeder creates a group with a request and well-formed availabilities', function () {
    $this->seed(DemoSeeder::class);

    $meeting = Meeting::query()->where('title', 'Assemblée de rentrée')->firstOrFail();

    expect($meeting->group->members)->toHaveCount(7)
        ->and(AvailabilityDay::query()->count())->toBeGreaterThan(0)
        ->and(AvailabilityDay::query()->pluck('cells')->every(fn (string $cells) => strlen($cells) === AvailabilityDay::CellCount))->toBeTrue();
});
