<?php

use App\Livewire\Availability\Grid;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
    $this->meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);
});

test('a member saves days and an empty day is removed', function () {
    $evening = str_repeat('0', 20).'pppp'.str_repeat('0', 4);

    Livewire::actingAs($this->member)
        ->test(Grid::class, ['meeting' => $this->meeting])
        ->call('saveDays', ['2026-11-04' => $evening, '2026-11-05' => $evening])
        ->assertHasNoErrors();

    expect(AvailabilityDay::where('user_id', $this->member->id)->orderBy('day')->pluck('cells', 'day')->all())
        ->toBe(['2026-11-04' => $evening, '2026-11-05' => $evening]);

    $component = Livewire::actingAs($this->member)->test(Grid::class, ['meeting' => $this->meeting]);

    expect($component->instance()->cells)->toBe(['2026-11-04' => $evening, '2026-11-05' => $evening]);

    $component->call('saveDays', ['2026-11-05' => AvailabilityDay::Empty]);

    expect(AvailabilityDay::pluck('day')->all())->toBe(['2026-11-04']);
});

test('days outside the range or malformed cells are refused', function (array $days) {
    Livewire::actingAs($this->member)
        ->test(Grid::class, ['meeting' => $this->meeting])
        ->call('saveDays', $days)
        ->assertHasErrors('days');

    expect(AvailabilityDay::count())->toBe(0);
})->with([
    'before the range' => [['2026-11-03' => str_repeat('p', 28)]],
    'after the range' => [['2026-11-11' => str_repeat('p', 28)]],
    'wrong length' => [['2026-11-04' => str_repeat('p', 27)]],
    'wrong letters' => [['2026-11-04' => str_repeat('x', 28)]],
]);

test('the deadline is indicative: saving after it is still allowed', function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 6));

    Livewire::actingAs($this->member)
        ->test(Grid::class, ['meeting' => $this->meeting])
        ->call('saveDays', ['2026-11-07' => str_repeat('d', 28)])
        ->assertHasNoErrors();

    expect(AvailabilityDay::count())->toBe(1);
});

test('the grid is read only once the vote has started', function () {
    $this->meeting->forceFill(['status' => 'voting'])->save();

    $component = Livewire::actingAs($this->member)->test(Grid::class, ['meeting' => $this->meeting]);

    expect($component->instance()->canEdit)->toBeFalse();

    $component->call('saveDays', ['2026-11-04' => str_repeat('p', 28)])->assertForbidden();
});

test('each member only writes their own grid, outsiders get a 404', function () {
    $other = User::factory()->create();
    $this->group->addMember($other);

    Livewire::actingAs($other)
        ->test(Grid::class, ['meeting' => $this->meeting])
        ->call('saveDays', ['2026-11-04' => str_repeat('p', 28)]);

    expect(AvailabilityDay::sole()->user_id)->toBe($other->id);

    Livewire::actingAs(User::factory()->create())
        ->test(Grid::class, ['meeting' => $this->meeting])
        ->assertNotFound();
});

test('weeks run from monday to sunday and mark days outside the range', function () {
    $weeks = Livewire::actingAs($this->member)
        ->test(Grid::class, ['meeting' => $this->meeting])
        ->instance()->weeks;

    expect($weeks)->toHaveCount(2)
        ->and($weeks[0][0])->toMatchArray(['date' => '2026-11-02', 'inRange' => false])
        ->and($weeks[0][2])->toMatchArray(['date' => '2026-11-04', 'inRange' => true])
        ->and($weeks[1][1])->toMatchArray(['date' => '2026-11-10', 'inRange' => true])
        ->and($weeks[1][2])->toMatchArray(['date' => '2026-11-11', 'inRange' => false]);
});

test('a refused save shows its error and a later valid save clears it', function () {
    $component = Livewire::actingAs($this->member)
        ->test(Grid::class, ['meeting' => $this->meeting])
        ->call('saveDays', ['2026-11-03' => str_repeat('p', 28)])
        ->assertHasErrors('days')
        ->assertSee(__('This availability could not be saved.'));

    $component->call('saveDays', ['2026-11-04' => str_repeat('p', 28)])
        ->assertHasNoErrors()
        ->assertDontSee(__('This availability could not be saved.'));
});

test('saveDays reports success to the browser', function () {
    Livewire::actingAs($this->member)
        ->test(Grid::class, ['meeting' => $this->meeting])
        ->call('saveDays', ['2026-11-04' => str_repeat('p', 28)])
        ->assertReturned(true);
});

test('saving keeps the grid state in the browser, so it stays on the shown week', function () {
    $component = Livewire::actingAs($this->member)->test(Grid::class);
    $xData = fn (): string => preg_match('/x-data="([^"]*)"/', $component->html(), $matches) ? $matches[1] : '';
    $before = $xData();

    $component->call('saveDays', ['2026-11-20' => str_repeat('p', 28)])->assertHasNoErrors();

    expect($xData())->toBe($before);
});
