<?php

use App\Enums\AvailabilityStatus;
use App\Enums\MeetingStatus;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\SlotVote;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('a new meeting collects availabilities over its range', function () {
    $meeting = Meeting::factory()->create([
        'range_start' => '2026-11-02',
        'range_end' => '2026-11-04',
    ]);

    expect($meeting->status)->toBe(MeetingStatus::Collecting)
        ->and($meeting->rangeDays())->toBe(['2026-11-02', '2026-11-03', '2026-11-04']);
});

test('cells map to half hours from 8:00 and local times convert to UTC around the clock change', function () {
    expect(AvailabilityDay::cellTime(0))->toBe('08:00')
        ->and(AvailabilityDay::cellTime(21))->toBe('18:30')
        ->and(AvailabilityDay::cellTime(28))->toBe('22:00')
        ->and(AvailabilityDay::localDateTime('2026-10-24', 20)->utc()->format('Y-m-d H:i'))->toBe('2026-10-24 16:00')
        ->and(AvailabilityDay::localDateTime('2026-10-25', 20)->utc()->format('Y-m-d H:i'))->toBe('2026-10-25 17:00');
});

test('a stretch of cells reads as on site, remote or unavailable', function () {
    $cells = str_repeat('0', 20).'ppppdd00';

    expect(AvailabilityDay::statusFor($cells, 20, 4))->toBe(AvailabilityStatus::OnSite)
        ->and(AvailabilityDay::statusFor($cells, 20, 6))->toBe(AvailabilityStatus::Remote)
        ->and(AvailabilityDay::statusFor($cells, 20, 7))->toBe(AvailabilityStatus::Unavailable)
        ->and(AvailabilityDay::statusFor($cells, 0, 2))->toBe(AvailabilityStatus::Unavailable);
});

test('cells are grouped per current member and day', function () {
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->addMember($member);
    $former = User::factory()->create();
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-11-02', 'range_end' => '2026-11-03']);
    AvailabilityDay::factory()->for($meeting)->for($member)->cells(str_repeat('p', 28))->create(['day' => '2026-11-02']);
    AvailabilityDay::factory()->for($meeting)->for($former)->cells(str_repeat('d', 28))->create(['day' => '2026-11-02']);

    expect($meeting->cellsByMember())->toBe([$member->id => ['2026-11-02' => str_repeat('p', 28)]])
        ->and($meeting->respondentIds()->all())->toBe([$member->id]);
});

test('removing a member deletes their grid and votes in the group', function () {
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->addMember($member);
    $meeting = Meeting::factory()->for($group)->create();
    AvailabilityDay::factory()->for($meeting)->for($member)->cells(str_repeat('p', 28))->create();
    $slot = MeetingSlot::factory()->for($meeting)->create();
    SlotVote::factory()->for($slot, 'slot')->for($member)->create();

    $group->removeMember($member);

    expect(AvailabilityDay::count())->toBe(0)->and(SlotVote::count())->toBe(0);
});

test('upcoming meetings are those not confirmed yet or confirmed in the future', function () {
    $collecting = Meeting::factory()->create();
    $future = Meeting::factory()->confirmed(now()->addWeek())->create();
    $past = Meeting::factory()->confirmed(now()->subWeek())->create();

    expect(Meeting::upcoming()->pluck('id')->sort()->values()->all())->toBe([$collecting->id, $future->id])
        ->and(Meeting::past()->pluck('id')->all())->toBe([$past->id]);
});

test('members fill their grid only while collecting and vote only while voting', function () {
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->addMember($member);
    $outsider = User::factory()->create();
    $collecting = Meeting::factory()->for($group)->create();
    $voting = Meeting::factory()->for($group)->voting()->create();

    expect(Gate::forUser($member)->allows('editAvailability', $collecting))->toBeTrue()
        ->and(Gate::forUser($member)->allows('editAvailability', $voting))->toBeFalse()
        ->and(Gate::forUser($member)->allows('vote', $voting))->toBeTrue()
        ->and(Gate::forUser($member)->allows('vote', $collecting))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('editAvailability', $collecting))->toBeFalse();
});
