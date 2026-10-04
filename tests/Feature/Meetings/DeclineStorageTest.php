<?php

use App\Actions\Availability\BusyCells;
use App\Enums\MeetingStatus;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingDecline;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->member = User::factory()->create();
    $this->other = User::factory()->create();
    $this->group->addMember($this->member);
    $this->group->addMember($this->other);
    $this->meeting = Meeting::factory()->for($this->group)
        ->confirmed(CarbonImmutable::parse('2026-11-05 17:00:00', 'UTC'))
        ->create();
});

test('a decline frees only the decliner', function () {
    $this->meeting->decliners()->attach($this->member->id);

    $busy = (new BusyCells)([$this->member->id, $this->other->id], '2026-11-05', '2026-11-05');

    expect($busy)->not->toHaveKey($this->member->id)
        ->and(array_keys($busy[$this->other->id]['2026-11-05']))->toBe([20, 21, 22, 23])
        ->and($this->meeting->isDeclinedBy($this->member))->toBeTrue();
});

test('undoing blocks again', function () {
    $this->meeting->decliners()->attach($this->member->id);
    $this->meeting->decliners()->detach($this->member->id);

    expect((new BusyCells)([$this->member->id], '2026-11-05', '2026-11-05'))->toHaveKey($this->member->id);
});

test('leaving clears declines', function () {
    $this->meeting->decliners()->attach($this->member->id);

    $this->group->removeMember($this->member);
    $this->group->addMember($this->member);

    expect($this->meeting->fresh()->isDeclinedBy($this->member))->toBeFalse();
});

test('deleting the meeting deletes its declines', function () {
    $this->meeting->decliners()->attach($this->member->id);
    $this->meeting->delete();

    expect(MeetingDecline::count())->toBe(0);
});

test('only members may decline, and only a confirmed meeting', function () {
    expect(Gate::forUser($this->member)->inspect('decline', $this->meeting)->allowed())->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->inspect('decline', $this->meeting)->status())->toBe(404);

    $this->meeting->forceFill(['status' => MeetingStatus::Voting])->save();

    expect(Gate::forUser($this->member)->inspect('decline', $this->meeting->fresh())->status())->toBe(403);
});
