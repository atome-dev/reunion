<?php

use App\Actions\Availability\BusyCells;
use App\Enums\AvailabilityStatus;
use App\Enums\MeetingStatus;
use App\Livewire\Meetings\Summary;
use App\Livewire\Meetings\Vote;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->groupA = Group::factory()->create(['name' => 'Bureau']);
    $this->member = User::factory()->create();
    $this->groupA->addMember($this->member);
    // Thursday 5 Nov 2026, 18:00–20:00 Paris = 17:00–19:00 UTC → cells 20..23
    $this->confirmed = Meeting::factory()->for($this->groupA)
        ->confirmed(CarbonImmutable::parse('2026-11-05 17:00:00', 'UTC'))
        ->create(['title' => 'Conseil secret', 'range_start' => '2026-11-03', 'range_end' => '2026-11-06', 'deadline' => '2026-11-02']);
});

test('a confirmed meeting blocks its cells for every current member', function () {
    $busy = (new BusyCells)([$this->member->id, $this->groupA->owner_id], '2026-11-02', '2026-11-08');

    expect(array_keys($busy[$this->member->id]['2026-11-05']))->toBe([20, 21, 22, 23])
        ->and($busy[$this->member->id]['2026-11-05'][20])->toMatchArray(['title' => 'Conseil secret', 'group' => 'Bureau'])
        ->and(array_keys($busy[$this->groupA->owner_id]['2026-11-05']))->toBe([20, 21, 22, 23]);
});

test('requests still collecting or voting block nothing', function () {
    $this->confirmed->forceFill(['status' => MeetingStatus::Voting])->save();

    expect((new BusyCells)([$this->member->id], '2026-11-02', '2026-11-08'))->toBe([]);
});

test('former members are not blocked and the request itself can be excluded', function () {
    expect((new BusyCells)([$this->member->id], '2026-11-02', '2026-11-08', $this->confirmed->id))->toBe([]);

    $this->groupA->removeMember($this->member);

    expect((new BusyCells)([$this->member->id], '2026-11-02', '2026-11-08'))->toBe([]);
});

test('cells follow Paris time across DST', function () {
    // 25 Oct 2026 is the switch to winter time: 18:30 Paris = 17:30 UTC
    Meeting::factory()->for($this->groupA)
        ->confirmed(CarbonImmutable::parse('2026-10-25 17:30:00', 'UTC'))
        ->create();

    $busy = (new BusyCells)([$this->member->id], '2026-10-25', '2026-10-25');

    expect(array_keys($busy[$this->member->id]['2026-10-25']))->toBe([21, 22, 23, 24]);
});

test('other groups only see unavailability', function () {
    $groupB = Group::factory()->create();
    $groupB->addMember($this->member);
    $request = Meeting::factory()->for($groupB)->create(['range_start' => '2026-11-05', 'range_end' => '2026-11-05', 'deadline' => '2026-11-04']);
    AvailabilityDay::factory()->for($this->member)->cells(str_repeat('0', 20).'pppp0000')->create(['day' => '2026-11-05']);
    AvailabilityDay::factory()->for($groupB->owner)->cells(str_repeat('0', 20).'pppp0000')->create(['day' => '2026-11-05']);

    $available = $request->availableCellsByMember();

    expect($available[$this->member->id]['2026-11-05'])->toBe(AvailabilityDay::Empty)
        ->and($available[$groupB->owner_id]['2026-11-05'])->toBe(str_repeat('0', 20).'pppp0000');

    Livewire::actingAs($groupB->owner)->test(Summary::class, ['meeting' => $request])
        ->set('duration', 120)
        ->assertDontSee('Conseil secret');
});

test('answering is unchanged', function () {
    $groupB = Group::factory()->create();
    $groupB->addMember($this->member);
    $request = Meeting::factory()->for($groupB)->create(['range_start' => '2026-11-05', 'range_end' => '2026-11-05', 'deadline' => '2026-11-04']);
    AvailabilityDay::factory()->for($this->member)->cells(str_repeat('0', 20).'pppp0000')->create(['day' => '2026-11-05']);

    expect($request->respondentIds()->all())->toContain($this->member->id);
});

test('the vote is prefilled unavailable on a busy slot', function () {
    $groupB = Group::factory()->create();
    $groupB->addMember($this->member);
    $request = Meeting::factory()->for($groupB)->voting()->create(['range_start' => '2026-11-05', 'range_end' => '2026-11-05', 'deadline' => '2026-11-04']);
    $slot = MeetingSlot::factory()->for($request)->create(['starts_at' => '2026-11-05 17:00:00', 'ends_at' => '2026-11-05 19:00:00']);
    AvailabilityDay::factory()->for($this->member)->cells(str_repeat('0', 20).'pppp0000')->create(['day' => '2026-11-05']);

    $component = Livewire::actingAs($this->member)->test(Vote::class, ['meeting' => $request]);

    expect($component->get('responses')[$slot->id])->toBe(AvailabilityStatus::Unavailable->value);
});
