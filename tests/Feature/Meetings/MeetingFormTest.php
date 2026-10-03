<?php

use App\Enums\MeetingStatus;
use App\Livewire\Meetings\Form;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingRequestedNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->organizer = $this->group->owner;
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
});

function fillRequest($component, array $overrides = [])
{
    $values = array_merge([
        'title' => 'Assemblée de rentrée',
        'location' => 'Salle des fêtes',
        'rangeStart' => '2026-11-09',
        'rangeEnd' => '2026-11-20',
        'deadline' => '2026-11-06',
    ], $overrides);

    foreach ($values as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

test('the organizer creates a request and members are emailed', function () {
    fillRequest(Livewire::actingAs($this->organizer)->test(Form::class, ['group' => $this->group]))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('meetings.show', Meeting::sole()));

    $meeting = Meeting::sole();
    expect($meeting->status)->toBe(MeetingStatus::Collecting)
        ->and($meeting->range_start->toDateString())->toBe('2026-11-09')
        ->and($meeting->deadline->toDateString())->toBe('2026-11-06');
    Notification::assertSentTo($this->member, MeetingRequestedNotification::class);
    Notification::assertNotSentTo($this->organizer, MeetingRequestedNotification::class);
});

test('range and deadline are validated', function (array $overrides, string $error) {
    fillRequest(Livewire::actingAs($this->organizer)->test(Form::class, ['group' => $this->group]), $overrides)
        ->call('save')
        ->assertHasErrors($error);

    expect(Meeting::count())->toBe(0);
})->with([
    'start in the past' => [['rangeStart' => '2026-11-01'], 'rangeStart'],
    'end before start' => [['rangeEnd' => '2026-11-08'], 'rangeEnd'],
    'more than 62 days' => [['rangeEnd' => '2027-01-12'], 'rangeEnd'],
    'deadline after the range' => [['deadline' => '2026-11-21'], 'deadline'],
    'deadline in the past' => [['deadline' => '2026-11-01'], 'deadline'],
    'missing title' => [['title' => ''], 'title'],
]);

test('shrinking the range keeps personal availabilities', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-11-09', 'range_end' => '2026-11-20', 'deadline' => '2026-11-06']);
    AvailabilityDay::factory()->for($this->member)->cells(str_repeat('p', 28))->create(['day' => '2026-11-10']);
    AvailabilityDay::factory()->for($this->member)->cells(str_repeat('p', 28))->create(['day' => '2026-11-19']);

    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['meeting' => $meeting])
        ->assertSet('rangeEnd', '2026-11-20')
        ->set('rangeEnd', '2026-11-15')
        ->call('save')
        ->assertHasNoErrors();

    expect(AvailabilityDay::orderBy('day')->pluck('day')->all())->toBe(['2026-11-10', '2026-11-19']);
});

test('only requests still collecting can be edited', function () {
    $voting = Meeting::factory()->for($this->group)->voting()->create();

    $this->actingAs($this->organizer)->get(route('meetings.edit', $voting))->assertForbidden();
});

test('the organizer deletes a request', function () {
    $meeting = Meeting::factory()->for($this->group)->create();

    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['meeting' => $meeting])
        ->call('deleteMeeting')
        ->assertRedirect(route('groups.show', $this->group));

    expect(Meeting::count())->toBe(0);
});

test('members cannot create or edit, outsiders get a 404', function () {
    $meeting = Meeting::factory()->for($this->group)->create();

    $this->actingAs($this->member)->get(route('meetings.create', $this->group))->assertForbidden();
    $this->actingAs($this->member)->get(route('meetings.edit', $meeting))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('meetings.create', $this->group))->assertNotFound();
});

test('a request whose range has started can still be edited', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-10-30', 'range_end' => '2026-11-20', 'deadline' => '2026-11-01']);
    AvailabilityDay::factory()->for($this->member)->cells(str_repeat('p', 28))->create(['day' => '2026-10-31']);

    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['meeting' => $meeting])
        ->set('title', 'Titre corrigé')
        ->call('save')
        ->assertHasNoErrors();

    expect($meeting->refresh()->title)->toBe('Titre corrigé')
        ->and(AvailabilityDay::count())->toBe(1);

    Livewire::actingAs($this->organizer)
        ->test(Form::class, ['meeting' => $meeting])
        ->set('rangeStart', '2026-10-31')
        ->call('save')
        ->assertHasErrors('rangeStart');
});
