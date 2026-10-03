<?php

use App\Livewire\Groups\Show as GroupShow;
use App\Livewire\Meetings\AvailabilityGrid;
use App\Livewire\Meetings\Show;
use App\Livewire\Meetings\Summary;
use App\Livewire\Meetings\Vote;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\SlotVote;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 10, 20)->setTime(10, 0));
    $this->group = Group::factory()->create(['name' => 'Collectif Test']);
    $this->organizer = $this->group->owner;
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
});

test('the organizer deletes a voting meeting from its page', function () {
    $meeting = Meeting::factory()->for($this->group)->voting()->create();

    Livewire::actingAs($this->organizer)
        ->test(Show::class, ['meeting' => $meeting])
        ->call('deleteMeeting')
        ->assertRedirect(route('groups.show', $this->group));

    expect(Meeting::find($meeting->id))->toBeNull();
});

test('the organizer deletes a confirmed meeting from its page', function () {
    $meeting = Meeting::factory()->for($this->group)->confirmed(now()->addWeek())->create();

    Livewire::actingAs($this->organizer)->test(Show::class, ['meeting' => $meeting])->call('deleteMeeting');

    expect(Meeting::find($meeting->id))->toBeNull();
});

test('a member cannot delete a meeting', function () {
    $meeting = Meeting::factory()->for($this->group)->voting()->create();

    Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])->call('deleteMeeting')->assertForbidden();

    expect(Meeting::find($meeting->id))->not->toBeNull();
});

test('the delete button is shown to the organizer only', function () {
    $meeting = Meeting::factory()->for($this->group)->voting()->create();

    $this->actingAs($this->organizer)->get(route('meetings.show', $meeting))->assertSee(__('Delete the request'));
    $this->actingAs($this->member)->get(route('meetings.show', $meeting))->assertDontSee(__('Delete the request'));
});

test('pages are titled after the meeting, the group and the form', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['title' => 'Assemblee Unique']);

    $this->actingAs($this->organizer)->get(route('meetings.show', $meeting))->assertSee('Assemblee Unique · ', false);
    $this->actingAs($this->organizer)->get(route('groups.show', $this->group))->assertSee('Collectif Test · ', false);
    $this->actingAs($this->organizer)->get(route('meetings.edit', $meeting))->assertSee(__('Edit the request').' · ', false);
    $this->actingAs($this->organizer)->get(route('meetings.create', $this->group))->assertSee(__('New request').' · ', false);
});

test('saving a grid notifies the summary, which refreshes its windows', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-10-24', 'range_end' => '2026-10-26', 'deadline' => '2026-10-23']);
    $evening = str_repeat('0', 20).'pppppppp';

    $summary = Livewire::actingAs($this->organizer)->test(Summary::class, ['meeting' => $meeting]);
    expect($summary->instance()->windows)->toBeEmpty();

    Livewire::actingAs($this->organizer)
        ->test(AvailabilityGrid::class, ['meeting' => $meeting])
        ->call('saveDays', ['2026-10-25' => $evening])
        ->assertDispatched('availability-saved');

    $summary->dispatch('availability-saved');

    expect($summary->instance()->windows)->toHaveCount(1)
        ->and($summary->instance()->nonRespondents->pluck('id')->all())->toBe([$this->member->id]);
});

test('saving the same day twice keeps a single row', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-10-24', 'range_end' => '2026-10-26', 'deadline' => '2026-10-23']);
    $component = Livewire::actingAs($this->member)->test(AvailabilityGrid::class, ['meeting' => $meeting]);

    $component->call('saveDays', ['2026-10-25' => str_repeat('0', 20).'pppppppp'])
        ->call('saveDays', ['2026-10-25' => str_repeat('0', 20).'dddddddd']);

    expect(AvailabilityDay::where('user_id', $this->member->id)->pluck('cells')->all())->toBe([str_repeat('0', 20).'dddddddd']);
});

test('voting twice keeps a single vote per slot', function () {
    $meeting = Meeting::factory()->for($this->group)->voting()->create();
    $slot = MeetingSlot::factory()->for($meeting)->create();
    MeetingSlot::factory()->for($meeting)->create();

    Livewire::actingAs($this->member)
        ->test(Vote::class, ['meeting' => $meeting])
        ->set("responses.{$slot->id}", 'on_site')->call('save')
        ->set("responses.{$slot->id}", 'remote')->call('save');

    expect(SlotVote::where('user_id', $this->member->id)->where('meeting_slot_id', $slot->id)->get()->map->status->map->value->all())->toBe(['remote']);
});

test('the summary lists the absent members of each window', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-10-24', 'range_end' => '2026-10-26', 'deadline' => '2026-10-23']);
    AvailabilityDay::factory()->for($this->organizer)->cells(str_repeat('0', 20).'pppppppp')->create(['day' => '2026-10-25']);

    Livewire::actingAs($this->organizer)
        ->test(Summary::class, ['meeting' => $meeting])
        ->assertSee(__('Absent').' :')
        ->assertSee($this->member->name);
});

test('the group page does not show a deadline for a meeting being voted', function () {
    $meeting = Meeting::factory()->for($this->group)->voting()->create(['title' => 'Vote en cours test']);

    $this->actingAs($this->member)->get(route('groups.show', $this->group))
        ->assertSee('Vote en cours test')
        ->assertDontSee(__('Answer before :date', ['date' => $meeting->deadline->translatedFormat('j F')]));
});

test('weeks of the grid start on monday whatever the locale', function () {
    app()->setLocale('en');
    $meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-10-28', 'range_end' => '2026-11-05', 'deadline' => '2026-10-27']);

    $weeks = Livewire::actingAs($this->member)->test(AvailabilityGrid::class, ['meeting' => $meeting])->instance()->weeks;

    expect($weeks[0][0]['date'])->toBe('2026-10-26')
        ->and(collect($weeks)->last()[6]['date'])->toBe('2026-11-08');
});

test('a slot factory never collides within a meeting', function () {
    $meeting = Meeting::factory()->for($this->group)->voting()->create();

    MeetingSlot::factory()->for($meeting)->count(40)->create();

    expect($meeting->slots()->count())->toBe(40);
});

test('a member removed mid-session gets a 404 on the next call', function (string $component, string $state, bool $organizerOnly) {
    $factory = Meeting::factory()->for($this->group);
    $meeting = ($state === 'voting' ? $factory->voting() : $factory)->create();
    $user = $organizerOnly ? $this->organizer : $this->member;

    $test = Livewire::actingAs($user)->test($component, ['meeting' => $meeting]);

    $this->group->removeMember($user);

    $test->call('$refresh')->assertNotFound();
})->with([
    'grid' => [AvailabilityGrid::class, 'collecting', false],
    'summary' => [Summary::class, 'collecting', true],
    'vote' => [Vote::class, 'voting', false],
    'show' => [Show::class, 'collecting', false],
]);

test('a failing invitation mail does not fail the invitation', function () {
    Event::listen(NotificationSending::class, fn () => throw new RuntimeException('smtp down'));

    Livewire::actingAs($this->organizer)
        ->test(GroupShow::class, ['group' => $this->group])
        ->set('invitationEmails', 'nouvelle@example.com')
        ->call('invite')
        ->assertHasNoErrors();

    expect(GroupInvitation::where('email', 'nouvelle@example.com')->exists())->toBeTrue();
});
