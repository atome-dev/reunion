<?php

use App\Livewire\Availability\Grid;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->user = User::factory()->create();
    $this->evening = str_repeat('0', 20).'pppp0000';
});

test('guests are sent to the login page', function () {
    $this->get(route('availability.edit'))->assertRedirect(route('login'));
});

test('a user without any group saves their availability', function () {
    $this->actingAs($this->user)->get(route('availability.edit'))->assertOk()->assertSeeLivewire(Grid::class);

    Livewire::actingAs($this->user)->test(Grid::class)
        ->call('saveDays', ['2026-11-05' => $this->evening])
        ->assertHasNoErrors()
        ->assertDispatched('availability-saved');

    expect(Livewire::actingAs($this->user)->test(Grid::class)->instance()->cells)->toBe(['2026-11-05' => $this->evening]);
});

test('the editable window follows Paris time and stops at three months', function (string $day, bool $accepted) {
    $this->travelTo(now('UTC')->setDate(2026, 11, 1)->setTime(23, 30)); // 2026-11-02 00:30 in Paris

    $component = Livewire::actingAs($this->user)->test(Grid::class)->call('saveDays', [$day => $this->evening]);

    $accepted ? $component->assertHasNoErrors() : $component->assertHasErrors('days');
})->with([
    'today' => ['2026-11-02', true],
    'yesterday' => ['2026-11-01', false],
    'three months ahead' => ['2027-02-02', true],
    'the day after' => ['2027-02-03', false],
]);

test('malformed cells are refused and nothing is written for anyone else', function () {
    $other = User::factory()->create();

    Livewire::actingAs($this->user)->test(Grid::class)
        ->call('saveDays', ['2026-11-05' => 'xx'])
        ->assertHasErrors('days');

    Livewire::actingAs($this->user)->test(Grid::class)->call('saveDays', ['2026-11-05' => $this->evening]);

    expect(AvailabilityDay::where('user_id', $other->id)->count())->toBe(0)
        ->and(AvailabilityDay::where('user_id', $this->user->id)->count())->toBe(1);
});

test('an empty day is removed', function () {
    AvailabilityDay::factory()->for($this->user)->cells($this->evening)->create(['day' => '2026-11-05']);

    Livewire::actingAs($this->user)->test(Grid::class)->call('saveDays', ['2026-11-05' => AvailabilityDay::Empty]);

    expect(AvailabilityDay::count())->toBe(0);
});

test('availability is shared between meetings and the personal page', function () {
    $groupA = Group::factory()->create();
    $groupB = Group::factory()->create();
    $groupA->addMember($this->user);
    $groupB->addMember($this->user);
    $meetingA = Meeting::factory()->for($groupA)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);
    $meetingB = Meeting::factory()->for($groupB)->create(['range_start' => '2026-11-05', 'range_end' => '2026-11-06', 'deadline' => '2026-11-04']);

    Livewire::actingAs($this->user)->test(Grid::class, ['meeting' => $meetingA])
        ->call('saveDays', ['2026-11-05' => $this->evening])
        ->assertHasNoErrors();

    expect(Livewire::actingAs($this->user)->test(Grid::class, ['meeting' => $meetingB])->instance()->cells)->toBe(['2026-11-05' => $this->evening])
        ->and(Livewire::actingAs($this->user)->test(Grid::class)->instance()->cells)->toBe(['2026-11-05' => $this->evening]);
});

test('a meeting grid refuses days outside the meeting range', function () {
    $group = Group::factory()->create();
    $group->addMember($this->user);
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);

    Livewire::actingAs($this->user)->test(Grid::class, ['meeting' => $meeting])
        ->call('saveDays', ['2026-11-20' => $this->evening])
        ->assertHasErrors('days');
});

test('past days of a meeting range are not editable', function () {
    $group = Group::factory()->create();
    $group->addMember($this->user);
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-10-30', 'range_end' => '2026-11-05', 'deadline' => '2026-10-29']);

    $component = Livewire::actingAs($this->user)->test(Grid::class, ['meeting' => $meeting]);
    $days = collect($component->instance()->weeks)->flatten(1)->keyBy('date');

    expect($days['2026-11-01']['inRange'])->toBeFalse()
        ->and($days['2026-11-02']['inRange'])->toBeTrue();

    $component->call('saveDays', ['2026-11-01' => $this->evening])->assertHasErrors('days');
});

test('the personal grid starts on the monday of the current week and ends three months ahead', function () {
    $days = collect(Livewire::actingAs($this->user)->test(Grid::class)->instance()->weeks)->flatten(1);

    expect($days->first()['date'])->toBe('2026-11-02')
        ->and($days->firstWhere('date', '2027-02-02')['inRange'])->toBeTrue()
        ->and($days->last()['date'])->toBe('2027-02-07');
});

test('the dashboard links to the personal page with the number of filled days', function () {
    AvailabilityDay::factory()->for($this->user)->cells($this->evening)->create(['day' => '2026-11-05']);
    AvailabilityDay::factory()->for($this->user)->cells($this->evening)->create(['day' => '2026-11-06']);

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertSee(route('availability.edit'))
        ->assertSee(trans_choice(':count day filled in over the next 3 months|:count days filled in over the next 3 months', 2));
});

test('the meeting page uses the shared grid and links to the whole calendar', function () {
    $group = Group::factory()->create();
    $group->addMember($this->user);
    $meeting = Meeting::factory()->for($group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);

    $this->actingAs($this->user)->get(route('meetings.show', $meeting))
        ->assertSeeLivewire(Grid::class)
        ->assertSee(__('See my whole calendar'));
});
