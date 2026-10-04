<?php

use App\Livewire\Meetings\Show;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->member = User::factory()->create(['name' => 'Amina']);
    $this->group->addMember($this->member);
    $this->meeting = Meeting::factory()->for($this->group)->confirmed()->create();
});

test('a member declines then comes back', function () {
    Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $this->meeting])
        ->assertSee(__("I won't come"))
        ->call('decline')
        ->assertSee(__("You said you won't come."))
        ->assertSee(__('Not coming: :names', ['names' => 'Amina']))
        ->call('undoDecline')
        ->assertSee(__("I won't come"));

    expect($this->meeting->fresh()->isDeclinedBy($this->member))->toBeFalse();
});

test('declining twice keeps one decline', function () {
    Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $this->meeting])->call('decline')->call('decline');

    expect($this->meeting->declines()->count())->toBe(1);
});

test('only confirmed meetings can be declined', function () {
    $collecting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-11-04', 'range_end' => '2026-11-10', 'deadline' => '2026-11-03']);

    Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $collecting])
        ->assertDontSee(__("I won't come"))
        ->call('decline')
        ->assertForbidden();
});

test('the dashboard flags a declined meeting', function () {
    $this->meeting->decliners()->attach($this->member->id);

    $this->actingAs($this->member)->get(route('dashboard'))->assertSee(__("You're not coming"));
});
