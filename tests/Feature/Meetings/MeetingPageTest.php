<?php

use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->organizer = $this->group->owner;
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
});

test('while collecting, members see their grid and the organizer sees their grid before the summary', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['title' => 'AG']);

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))
        ->assertOk()->assertSeeLivewire('meetings.availability-grid')->assertDontSeeLivewire('meetings.summary');

    $this->actingAs($this->organizer)->get(route('meetings.show', $meeting))
        ->assertSeeLivewire('meetings.summary')->assertSeeLivewire('meetings.availability-grid')
        ->assertSeeInOrder([__('My availability'), __('Best slots')]);
});

test('while voting, the vote is shown', function () {
    $meeting = Meeting::factory()->for($this->group)->voting()->create();

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))->assertSeeLivewire('meetings.vote');
});

test('a confirmed meeting shows its date and offers the calendar file', function () {
    $meeting = Meeting::factory()->for($this->group)->confirmed(now('UTC')->setDate(2026, 11, 3)->setTime(17, 0))->create(['location' => 'Salle des fêtes']);

    $this->actingAs($this->member)->get(route('meetings.show', $meeting))
        ->assertSee('Salle des fêtes')
        ->assertSee(route('meetings.calendar', $meeting), escape: false);

    $this->actingAs($this->member)->get(route('meetings.calendar', $meeting))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertSee('DTSTART:20261103T170000Z', escape: false);
});

test('the calendar file is only for confirmed meetings and members', function () {
    $collecting = Meeting::factory()->for($this->group)->create();
    $confirmed = Meeting::factory()->for($this->group)->confirmed()->create();

    $this->actingAs($this->member)->get(route('meetings.calendar', $collecting))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('meetings.calendar', $confirmed))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('meetings.show', $confirmed))->assertNotFound();
});
