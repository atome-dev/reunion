<?php

use App\Enums\MeetingStatus;
use App\Livewire\Groups\Show as GroupShow;
use App\Livewire\Meetings\Form as MeetingForm;
use App\Livewire\Meetings\Show as MeetingShow;
use App\Livewire\Meetings\Summary;
use App\Livewire\Meetings\Vote;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\SlotVote;
use App\Models\User;
use App\Notifications\MeetingRequestedNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->creator = $this->group->owner;
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
    $this->outsider = User::factory()->create();
});

function requestFor(Group $group, User $author, array $attributes = []): Meeting
{
    return Meeting::factory()->for($group)->create(['created_by' => $author->id, 'range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03', ...$attributes]);
}

test('settings default to the group creator only', function () {
    $group = Group::factory()->create()->fresh();

    expect($group->members_can_request_meetings)->toBeFalse()
        ->and($group->members_can_invite)->toBeFalse()
        ->and($group->members_can_validate)->toBeFalse();
});

test('requesting a meeting follows the setting', function () {
    $this->actingAs($this->member)->get(route('meetings.create', $this->group))->assertForbidden();
    $this->actingAs($this->creator)->get(route('meetings.create', $this->group))->assertOk();

    $this->group->update(['members_can_request_meetings' => true]);

    $this->actingAs($this->member)->get(route('meetings.create', $this->group))->assertOk();
    $this->actingAs($this->outsider)->get(route('meetings.create', $this->group))->assertNotFound();
});

test('inviting follows the setting', function () {
    $invitation = GroupInvitation::factory()->for($this->group)->create();

    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])
        ->set('invitationEmails', 'amina@example.com')
        ->call('invite')
        ->assertForbidden();

    $this->group->update(['members_can_invite' => true]);

    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])
        ->set('invitationEmails', 'amina@example.com')
        ->call('invite')
        ->assertOk();

    expect(GroupInvitation::where('group_id', $this->group->id)->where('email', 'amina@example.com')->exists())->toBeTrue();

    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])
        ->call('cancelInvitation', $invitation->id)
        ->assertOk();

    expect(GroupInvitation::find($invitation->id))->toBeNull();
});

test('a member saves a request when requests are open', function () {
    $this->group->update(['members_can_request_meetings' => true]);

    Livewire::actingAs($this->member)->test(MeetingForm::class, ['group' => $this->group])
        ->set('title', 'Assemblée de rentrée')
        ->set('rangeStart', '2026-11-09')
        ->set('rangeEnd', '2026-11-20')
        ->set('deadline', '2026-11-06')
        ->call('save')
        ->assertHasNoErrors();

    expect(Meeting::sole()->created_by)->toBe($this->member->id);
    Notification::assertSentTo($this->creator, MeetingRequestedNotification::class);
    Notification::assertNotSentTo($this->member, MeetingRequestedNotification::class);
});

test('deleting the author keeps the request, its votes and shows a fallback name', function () {
    $meeting = requestFor($this->group, $this->member, ['status' => MeetingStatus::Voting]);
    $slot = MeetingSlot::factory()->for($meeting)->create(['starts_at' => '2026-11-05 17:00:00', 'ends_at' => '2026-11-05 19:00:00']);
    $voter = User::factory()->create();
    $this->group->addMember($voter);
    SlotVote::factory()->create(['meeting_slot_id' => $slot->id, 'user_id' => $voter->id]);

    $this->member->delete();

    expect($meeting->fresh()->created_by)->toBeNull()
        ->and($slot->votes()->count())->toBe(1);
    $this->actingAs($this->creator)->get(route('meetings.show', $meeting))->assertOk()->assertSee(__('Requested by :name', ['name' => __('a former member')]));
    $this->actingAs($voter)->get(route('meetings.edit', $meeting))->assertForbidden();
});

test('deleting the inviter keeps the pending invitation', function () {
    $invitation = GroupInvitation::factory()->for($this->group)->create(['invited_by' => $this->member->id]);

    $this->member->delete();

    expect($invitation->fresh())->not->toBeNull()
        ->and($invitation->fresh()->invited_by)->toBeNull();
});

test('only a non-creator member sees the leave button', function () {
    $this->actingAs($this->member)->get(route('groups.show', $this->group))->assertSee(__('Leave the group'));
    $this->actingAs($this->creator)->get(route('groups.show', $this->group))->assertDontSee(__('Leave the group'));
});

test('validating follows the setting', function () {
    $meeting = requestFor($this->group, $this->creator);

    Livewire::actingAs($this->member)->test(Summary::class, ['meeting' => $meeting])->assertForbidden();

    $this->group->update(['members_can_validate' => true]);

    Livewire::actingAs($this->member)->test(Summary::class, ['meeting' => $meeting->fresh()])->assertOk();
});

test('confirming or cancelling a vote follows the validation setting', function () {
    $meeting = requestFor($this->group, $this->creator, ['status' => MeetingStatus::Voting]);
    $slot = MeetingSlot::factory()->for($meeting)->create(['starts_at' => '2026-11-05 17:00:00', 'ends_at' => '2026-11-05 19:00:00']);

    Livewire::actingAs($this->member)->test(Vote::class, ['meeting' => $meeting])
        ->call('confirmSlot', $slot->id)
        ->assertForbidden();

    $this->group->update(['members_can_validate' => true]);

    Livewire::actingAs($this->member)->test(Vote::class, ['meeting' => $meeting])
        ->call('confirmSlot', $slot->id)
        ->assertHasNoErrors();

    expect($meeting->fresh()->status)->toBe(MeetingStatus::Confirmed);
});

test('the author edits but does not validate', function () {
    $meeting = requestFor($this->group, $this->member);

    $this->actingAs($this->member)->get(route('meetings.edit', $meeting))->assertOk();
    Livewire::actingAs($this->member)->test(Summary::class, ['meeting' => $meeting])->assertForbidden();

    Livewire::actingAs($this->member)->test(MeetingShow::class, ['meeting' => $meeting])->call('deleteMeeting');

    expect(Meeting::find($meeting->id))->toBeNull();
});

test('another member cannot edit or delete a request, even with validation open', function () {
    $other = User::factory()->create();
    $this->group->addMember($other);
    $this->group->update(['members_can_validate' => true]);
    $meeting = requestFor($this->group, $this->member);

    $this->actingAs($other)->get(route('meetings.edit', $meeting))->assertForbidden();
    Livewire::actingAs($other)->test(MeetingShow::class, ['meeting' => $meeting])->call('deleteMeeting')->assertForbidden();
});

test('the group creator edits and deletes any request', function () {
    $meeting = requestFor($this->group, $this->member);

    $this->actingAs($this->creator)->get(route('meetings.edit', $meeting))->assertOk();
    Livewire::actingAs($this->creator)->test(MeetingShow::class, ['meeting' => $meeting])->call('deleteMeeting');

    expect(Meeting::find($meeting->id))->toBeNull();
});

test('authorship outlives the setting', function () {
    $this->group->update(['members_can_request_meetings' => true]);
    $meeting = requestFor($this->group, $this->member);
    $this->group->update(['members_can_request_meetings' => false]);

    $this->actingAs($this->member)->get(route('meetings.edit', $meeting))->assertOk();
});

test('settings never open group administration', function () {
    $this->group->update(['members_can_request_meetings' => true, 'members_can_invite' => true, 'members_can_validate' => true]);
    $other = User::factory()->create();
    $this->group->addMember($other);

    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])->set('name', 'Pirate')->call('rename')->assertForbidden();
    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])->call('deleteGroup')->assertForbidden();
    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])->call('removeMember', $other->id)->assertForbidden();

    expect($this->group->fresh()->name)->not->toBe('Pirate')
        ->and($this->group->hasMember($other))->toBeTrue();
});

test('non-members get 404', function (string $ability) {
    $meeting = requestFor($this->group, $this->creator);
    $this->group->update(['members_can_request_meetings' => true, 'members_can_invite' => true, 'members_can_validate' => true]);

    $target = in_array($ability, ['update', 'validate'], true) ? $meeting : $this->group;

    expect(Gate::forUser($this->outsider)->inspect($ability, $target)->status())->toBe(404);

    if ($ability === 'update') {
        expect(Gate::forUser($this->outsider)->inspect('update', $this->group)->status())->toBe(404);
    }
})->with(['requestMeeting', 'invite', 'update', 'validate']);
