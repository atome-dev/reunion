<?php

use App\Livewire\Groups\Show as GroupShow;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create()->fresh();
    $this->creator = $this->group->owner;
    $this->member = User::factory()->create(['name' => 'Bastien']);
    $this->group->addMember($this->member);
});

test('the creator changes the settings and they are saved', function () {
    Livewire::actingAs($this->creator)->test(GroupShow::class, ['group' => $this->group])
        ->assertSee(__('Settings'))
        ->set('requestMeetingsAudience', 'members')
        ->set('validateAudience', 'members')
        ->assertHasNoErrors();

    $group = $this->group->fresh();

    expect($group->members_can_request_meetings)->toBeTrue()
        ->and($group->members_can_invite)->toBeFalse()
        ->and($group->members_can_validate)->toBeTrue();
});

test('a member neither sees nor changes the settings', function () {
    Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group])
        ->assertDontSee(__('Settings'))
        ->set('inviteAudience', 'members')
        ->assertForbidden();

    expect($this->group->fresh()->members_can_invite)->toBeFalse();
});

test('a member sees the actions the settings open to them', function () {
    $page = fn () => $this->actingAs($this->member)->get(route('groups.show', $this->group));

    $page()->assertDontSee(__('New request'))->assertDontSee(__('Invite people'));

    $this->group->update(['members_can_request_meetings' => true, 'members_can_invite' => true]);

    $page()->assertSee(__('New request'))->assertSee(__('Invite people'))->assertDontSee(__('Delete the group'));
});

test('a setting turned off applies to the next request', function () {
    $this->group->update(['members_can_invite' => true]);
    $component = Livewire::actingAs($this->member)->test(GroupShow::class, ['group' => $this->group]);

    $this->group->update(['members_can_invite' => false]);

    $component->set('invitationEmails', 'amina@example.com')->call('invite')->assertForbidden();
});

test('the meeting page shows the author and actions by right', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['created_by' => $this->member->id, 'range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))
        ->assertSee(__('Requested by :name', ['name' => 'Bastien']))
        ->assertSee(__('Edit the request'))
        ->assertDontSee(__('Best slots'));

    $this->group->update(['members_can_validate' => true]);

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))->assertSee(__('Best slots'));
});

test('the vote results and actions follow the validation setting', function () {
    $meeting = Meeting::factory()->for($this->group)->voting()->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))->assertDontSee(__('Cancel the vote'));

    $this->group->update(['members_can_validate' => true]);

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))->assertSee(__('Cancel the vote'));
});

test('an unknown audience is refused and nothing changes', function () {
    Livewire::actingAs($this->creator)->test(GroupShow::class, ['group' => $this->group])
        ->set('validateAudience', 'everyone')
        ->assertHasErrors('validateAudience');

    expect($this->group->fresh()->members_can_validate)->toBeFalse();
});

test('the creator badge reads group creator', function () {
    $this->actingAs($this->member)->get(route('groups.show', $this->group))->assertSee(__('Group creator'));
});
