<?php

use App\Enums\MeetingStatus;
use App\Livewire\Meetings\Summary;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 10, 20)->setTime(10, 0));
    $this->group = Group::factory()->create();
    $this->organizer = $this->group->owner;
    $this->amina = User::factory()->create(['name' => 'Amina']);
    $this->bastien = User::factory()->create(['name' => 'Bastien']);
    $this->silent = User::factory()->create(['name' => 'Chloé']);
    foreach ([$this->amina, $this->bastien, $this->silent] as $member) {
        $this->group->addMember($member);
    }
    $this->meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-10-24', 'range_end' => '2026-10-26', 'deadline' => '2026-10-23']);
    $evening = str_repeat('0', 20).'pppppp00';
    AvailabilityDay::factory()->for($this->meeting)->for($this->amina)->cells($evening)->create(['day' => '2026-10-25']);
    AvailabilityDay::factory()->for($this->meeting)->for($this->bastien)->cells(str_repeat('0', 20).'dddddd00')->create(['day' => '2026-10-25']);
    AvailabilityDay::factory()->for($this->meeting)->for($this->amina)->cells($evening)->create(['day' => '2026-10-26']);
});

test('the organizer sees the best windows and who has not answered', function () {
    $component = Livewire::actingAs($this->organizer)->test(Summary::class, ['meeting' => $this->meeting]);
    $windows = $component->instance()->windows;

    expect($windows[0])->toMatchArray(['day' => '2026-10-25', 'firstStart' => 20, 'lastStart' => 22, 'key' => '2026-10-25|20'])
        ->and($component->instance()->nonRespondents->pluck('name')->all())->toEqualCanonicalizing(['Chloé', $this->organizer->name]);

    $component->assertSee('Amina')->assertSee('Bastien');
});

test('settings narrow the windows', function () {
    Livewire::actingAs($this->organizer)
        ->test(Summary::class, ['meeting' => $this->meeting])
        ->set('minParticipants', 2)
        ->set('minOnSite', 2)
        ->assertSee(__('No slot matches these criteria'));
});

test('confirming a window stores local times in UTC across the clock change', function () {
    Livewire::actingAs($this->organizer)
        ->test(Summary::class, ['meeting' => $this->meeting])
        ->set('starts.2026-10-25|20', 21)
        ->call('confirmWindow', '2026-10-25|20')
        ->assertRedirect(route('meetings.show', $this->meeting));

    $meeting = $this->meeting->fresh();
    expect($meeting->status)->toBe(MeetingStatus::Confirmed)
        ->and($meeting->confirmed_starts_at->format('Y-m-d H:i'))->toBe('2026-10-25 17:30')
        ->and($meeting->confirmed_ends_at->format('Y-m-d H:i'))->toBe('2026-10-25 19:30');
});

test('a start outside the window is refused', function () {
    Livewire::actingAs($this->organizer)
        ->test(Summary::class, ['meeting' => $this->meeting])
        ->set('starts.2026-10-25|20', 5)
        ->call('confirmWindow', '2026-10-25|20')
        ->assertHasErrors('starts');

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Collecting);
});

test('several windows open a vote, duplicates are merged and one is not enough', function () {
    Livewire::actingAs($this->organizer)
        ->test(Summary::class, ['meeting' => $this->meeting])
        ->set('selected', ['2026-10-25|20'])
        ->call('openVote')
        ->assertHasErrors('selected');

    Livewire::actingAs($this->organizer)
        ->test(Summary::class, ['meeting' => $this->meeting])
        ->set('selected', ['2026-10-25|20', '2026-10-26|20'])
        ->call('openVote')
        ->assertHasNoErrors()
        ->assertRedirect(route('meetings.show', $this->meeting));

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Voting)
        ->and($this->meeting->slots()->count())->toBe(2);
});

test('members cannot see the summary, outsiders get a 404', function () {
    Livewire::actingAs($this->amina)->test(Summary::class, ['meeting' => $this->meeting])->assertForbidden();
    Livewire::actingAs(User::factory()->create())->test(Summary::class, ['meeting' => $this->meeting])->assertNotFound();
});
