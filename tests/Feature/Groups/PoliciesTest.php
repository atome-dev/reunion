<?php

use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->organizer = $this->group->owner;
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
    $this->outsider = User::factory()->create();
    $this->meeting = Meeting::factory()->for($this->group)->create();
});

test('only members can see a group and its meetings, others get a 404', function () {
    expect(Gate::forUser($this->member)->allows('view', $this->group))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('view', $this->meeting))->toBeTrue()
        ->and(Gate::forUser($this->outsider)->inspect('view', $this->group)->status())->toBe(404)
        ->and(Gate::forUser($this->outsider)->inspect('view', $this->meeting)->status())->toBe(404);
});

test('by default only the creator administers, a member gets a 403 and an outsider a 404', function () {
    expect(Gate::forUser($this->organizer)->allows('update', $this->group))->toBeTrue()
        ->and(Gate::forUser($this->organizer)->allows('requestMeeting', $this->group))->toBeTrue()
        ->and(Gate::forUser($this->organizer)->allows('invite', $this->group))->toBeTrue()
        ->and(Gate::forUser($this->organizer)->allows('update', $this->meeting))->toBeTrue()
        ->and(Gate::forUser($this->organizer)->allows('validate', $this->meeting))->toBeTrue()
        ->and(Gate::forUser($this->member)->inspect('update', $this->group)->status())->toBe(403)
        ->and(Gate::forUser($this->member)->inspect('requestMeeting', $this->group)->status())->toBe(403)
        ->and(Gate::forUser($this->member)->inspect('invite', $this->group)->status())->toBe(403)
        ->and(Gate::forUser($this->member)->inspect('update', $this->meeting)->status())->toBe(403)
        ->and(Gate::forUser($this->member)->inspect('validate', $this->meeting)->status())->toBe(403)
        ->and(Gate::forUser($this->outsider)->inspect('update', $this->group)->status())->toBe(404);
});

test('only members can fill a grid or vote', function () {
    $voting = Meeting::factory()->for($this->group)->voting()->create();

    expect(Gate::forUser($this->member)->allows('editAvailability', $this->meeting))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('vote', $voting))->toBeTrue()
        ->and(Gate::forUser($this->outsider)->allows('editAvailability', $this->meeting))->toBeFalse()
        ->and(Gate::forUser($this->outsider)->allows('vote', $voting))->toBeFalse();
});

test('a member can leave but the organizer cannot', function () {
    expect(Gate::forUser($this->member)->allows('leave', $this->group))->toBeTrue()
        ->and(Gate::forUser($this->organizer)->allows('leave', $this->group))->toBeFalse();
});
