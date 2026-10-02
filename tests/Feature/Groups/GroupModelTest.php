<?php

use App\Enums\AvailabilityStatus;
use App\Enums\GroupRole;
use App\Models\Availability;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\User;

test('the owner of a new group is its organizer', function () {
    $group = Group::factory()->create();

    expect($group->isOrganizer($group->owner))->toBeTrue()
        ->and($group->hasMember($group->owner))->toBeTrue()
        ->and($group->members()->first()->pivot->role)->toBe(GroupRole::Organizer->value);
});

test('members can be added and removed with their answers', function () {
    $group = Group::factory()->create();
    $member = User::factory()->create();
    $group->addMember($member);
    $slot = MeetingSlot::factory()->for(Meeting::factory()->for($group))->create();
    Availability::factory()->for($slot, 'slot')->for($member)->create();

    $group->removeMember($member);

    expect($group->hasMember($member))->toBeFalse()
        ->and(Availability::count())->toBe(0);
});

test('deleting a group deletes its meetings, slots, answers and invitations', function () {
    $group = Group::factory()->create();
    $slot = MeetingSlot::factory()->for(Meeting::factory()->for($group))->create();
    Availability::factory()->for($slot, 'slot')->for($group->owner)->create();
    GroupInvitation::factory()->for($group)->create();

    $group->delete();

    expect(Meeting::count())->toBe(0)
        ->and(MeetingSlot::count())->toBe(0)
        ->and(Availability::count())->toBe(0)
        ->and(GroupInvitation::count())->toBe(0);
});

test('an invitation is found by its token and only usable while pending', function () {
    $invitation = GroupInvitation::factory()->create(['token_hash' => hash('sha256', 'secret-token')]);

    expect(GroupInvitation::findByToken('secret-token')?->is($invitation))->toBeTrue()
        ->and(GroupInvitation::findByToken('wrong'))->toBeNull()
        ->and($invitation->isUsable())->toBeTrue()
        ->and(GroupInvitation::factory()->expired()->create()->isUsable())->toBeFalse()
        ->and(GroupInvitation::factory()->accepted()->create()->isUsable())->toBeFalse();
});

test('meetings are upcoming while one of their dates is ahead', function () {
    $upcoming = Meeting::factory()->create();
    MeetingSlot::factory()->for($upcoming)->create(['starts_at' => now()->subDay()]);
    MeetingSlot::factory()->for($upcoming)->create(['starts_at' => now()->addDay()]);
    $past = Meeting::factory()->create();
    MeetingSlot::factory()->for($past)->create(['starts_at' => now()->subDay()]);

    expect(Meeting::upcoming()->pluck('id')->all())->toBe([$upcoming->id])
        ->and(Meeting::past()->pluck('id')->all())->toBe([$past->id])
        ->and($past->isPast())->toBeTrue();
});

test('slots are shown in the display timezone and statuses have labels', function () {
    $slot = MeetingSlot::factory()->create(['starts_at' => '2026-10-14 16:30:00']);

    expect($slot->startsAtLocal()->format('H:i'))->toBe('18:30')
        ->and(AvailabilityStatus::OnSite->label())->toBe(__('On site'));
});
