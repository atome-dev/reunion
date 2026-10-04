<?php

use App\Livewire\Availability\Grid;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create(['name' => 'Bureau']);
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
    $this->meeting = Meeting::factory()->for($this->group)
        ->confirmed(CarbonImmutable::parse('2026-11-05 17:00:00', 'UTC'))
        ->create(['title' => 'Conseil']);
});

test('the grid exposes the meeting cells to each member', function () {
    $busy = Livewire::actingAs($this->member)->test(Grid::class)->instance()->busy;

    expect(array_keys($busy['2026-11-05']))->toBe([20, 21, 22, 23])
        ->and($busy['2026-11-05'][20])->toBe(['title' => 'Conseil', 'group' => 'Bureau']);
});

test('changes under a meeting are refused, the rest of the day is accepted', function () {
    $underMeeting = str_repeat('0', 20).'p'.str_repeat('0', 7);
    $beside = 'pp'.str_repeat('0', 26);

    Livewire::actingAs($this->member)->test(Grid::class)
        ->call('saveDays', ['2026-11-05' => $underMeeting])
        ->assertHasErrors('days');

    Livewire::actingAs($this->member)->test(Grid::class)
        ->call('saveDays', ['2026-11-05' => $beside])
        ->assertHasNoErrors();

    expect(AvailabilityDay::where('user_id', $this->member->id)->value('cells'))->toBe($beside);
});

test('availability filled before the meeting is kept and can still be saved unchanged', function () {
    $evening = str_repeat('0', 20).'pppp0000';
    AvailabilityDay::factory()->for($this->member)->cells($evening)->create(['day' => '2026-11-05']);

    Livewire::actingAs($this->member)->test(Grid::class)
        ->call('saveDays', ['2026-11-05' => 'p'.substr($evening, 1)])
        ->assertHasNoErrors();
});

test('a deleted meeting frees its cells', function () {
    $this->meeting->delete();

    expect(Livewire::actingAs($this->member)->test(Grid::class)->instance()->busy)->toBe([]);
});

test('the busy cells reach the browser outside x-data', function () {
    $html = Livewire::actingAs($this->member)->test(Grid::class)->html();

    expect($html)->toContain('data-busy=')->toContain('Conseil');
});
