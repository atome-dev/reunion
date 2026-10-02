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

test('only the organizer manages, a member gets a 403 and an outsider a 404', function () {
    expect(Gate::forUser($this->organizer)->allows('manage', $this->group))->toBeTrue()
        ->and(Gate::forUser($this->organizer)->allows('manage', $this->meeting))->toBeTrue()
        ->and(Gate::forUser($this->member)->inspect('manage', $this->group)->status())->toBe(403)
        ->and(Gate::forUser($this->member)->inspect('manage', $this->meeting)->status())->toBe(403)
        ->and(Gate::forUser($this->outsider)->inspect('manage', $this->group)->status())->toBe(404);
});

test('members respond, outsiders cannot', function () {
    expect(Gate::forUser($this->member)->allows('respond', $this->meeting))->toBeTrue()
        ->and(Gate::forUser($this->organizer)->allows('respond', $this->meeting))->toBeTrue()
        ->and(Gate::forUser($this->outsider)->allows('respond', $this->meeting))->toBeFalse();
});

test('a member can leave but the organizer cannot', function () {
    expect(Gate::forUser($this->member)->allows('leave', $this->group))->toBeTrue()
        ->and(Gate::forUser($this->organizer)->allows('leave', $this->group))->toBeFalse();
});
