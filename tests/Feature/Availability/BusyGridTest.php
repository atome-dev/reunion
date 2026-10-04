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

test('changes under a meeting are ignored, the rest of the day is saved', function () {
    $mixed = 'pp'.str_repeat('0', 18).'pp'.str_repeat('0', 6);

    Livewire::actingAs($this->member)->test(Grid::class)
        ->call('saveDays', ['2026-11-05' => $mixed])
        ->assertHasNoErrors();

    expect(AvailabilityDay::where('user_id', $this->member->id)->value('cells'))->toBe('pp'.str_repeat('0', 26));
});

test('a stored value under a meeting is kept when the client sends another one', function () {
    $evening = str_repeat('0', 20).'dddd0000';
    AvailabilityDay::factory()->for($this->member)->cells($evening)->create(['day' => '2026-11-05']);

    Livewire::actingAs($this->member)->test(Grid::class)
        ->call('saveDays', ['2026-11-05' => 'p'.str_repeat('0', 19).'pppp0000'])
        ->assertHasNoErrors();

    expect(AvailabilityDay::where('user_id', $this->member->id)->value('cells'))->toBe('p'.str_repeat('0', 19).'dddd0000');
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
