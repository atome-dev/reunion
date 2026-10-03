<?php

use App\Enums\AvailabilityStatus;
use App\Enums\MeetingStatus;
use App\Livewire\Meetings\Vote;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\SlotVote;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->group = Group::factory()->create();
    $this->organizer = $this->group->owner;
    $this->member = User::factory()->create(['name' => 'Bastien']);
    $this->group->addMember($this->member);
    $this->meeting = Meeting::factory()->for($this->group)->voting()->create(['range_start' => '2026-11-02', 'range_end' => '2026-11-06']);
    $this->monday = MeetingSlot::factory()->for($this->meeting)->create(['starts_at' => '2026-11-02 17:00:00', 'ends_at' => '2026-11-02 19:00:00']);
    $this->tuesday = MeetingSlot::factory()->for($this->meeting)->create(['starts_at' => '2026-11-03 17:00:00', 'ends_at' => '2026-11-03 19:00:00']);
});

test('answers are prefilled from the grid', function () {
    AvailabilityDay::factory()->for($this->member)->cells(str_repeat('0', 20).'pppp0000')->create(['day' => '2026-11-02']);
    AvailabilityDay::factory()->for($this->member)->cells(str_repeat('0', 20).'ppdd0000')->create(['day' => '2026-11-03']);

    Livewire::actingAs($this->member)
        ->test(Vote::class, ['meeting' => $this->meeting])
        ->assertSet("responses.{$this->monday->id}", 'on_site')
        ->assertSet("responses.{$this->tuesday->id}", 'remote');
});

test('a member votes for every slot', function () {
    Livewire::actingAs($this->member)
        ->test(Vote::class, ['meeting' => $this->meeting])
        ->set("responses.{$this->monday->id}", 'on_site')
        ->set("responses.{$this->tuesday->id}", '')
        ->call('save')
        ->assertHasErrors("responses.{$this->tuesday->id}")
        ->set("responses.{$this->tuesday->id}", 'unavailable')
        ->call('save')
        ->assertHasNoErrors();

    expect(SlotVote::where('user_id', $this->member->id)->count())->toBe(2);
});

test('the organizer sees the best date and confirms it', function () {
    SlotVote::factory()->for($this->tuesday, 'slot')->for($this->member)->create(['status' => AvailabilityStatus::OnSite]);
    SlotVote::factory()->for($this->monday, 'slot')->for($this->member)->create(['status' => AvailabilityStatus::Unavailable]);

    $component = Livewire::actingAs($this->organizer)->test(Vote::class, ['meeting' => $this->meeting]);

    expect($component->instance()->bestSlotId)->toBe($this->tuesday->id)
        ->and($component->instance()->nonVoters->pluck('id')->all())->toBe([$this->organizer->id]);

    $component->call('confirmSlot', $this->tuesday->id)->assertRedirect(route('meetings.show', $this->meeting));

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Confirmed)
        ->and($this->meeting->fresh()->confirmed_starts_at->format('Y-m-d H:i'))->toBe('2026-11-03 17:00');
});

test('a slot of another meeting cannot be confirmed', function () {
    $foreign = MeetingSlot::factory()->create();

    Livewire::actingAs($this->organizer)
        ->test(Vote::class, ['meeting' => $this->meeting])
        ->call('confirmSlot', $foreign->id)
        ->assertNotFound();
});

test('the organizer cancels the vote', function () {
    Livewire::actingAs($this->organizer)
        ->test(Vote::class, ['meeting' => $this->meeting])
        ->call('cancelVote')
        ->assertRedirect(route('meetings.show', $this->meeting));

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Collecting);
});

test('members cannot confirm or cancel, nobody votes once confirmed', function () {
    Livewire::actingAs($this->member)->test(Vote::class, ['meeting' => $this->meeting])->call('confirmSlot', $this->monday->id)->assertForbidden();
    Livewire::actingAs($this->member)->test(Vote::class, ['meeting' => $this->meeting])->call('cancelVote')->assertForbidden();

    $this->meeting->forceFill(['status' => MeetingStatus::Confirmed, 'confirmed_starts_at' => now(), 'confirmed_ends_at' => now()->addHour()])->save();

    Livewire::actingAs($this->member)
        ->test(Vote::class, ['meeting' => $this->meeting])
        ->set("responses.{$this->monday->id}", 'on_site')
        ->set("responses.{$this->tuesday->id}", 'on_site')
        ->call('save')
        ->assertForbidden();
});

test('confirming a slot once the vote is no longer open shows an error', function () {
    $this->meeting->forceFill(['status' => MeetingStatus::Collecting])->save();

    Livewire::actingAs($this->organizer)
        ->test(Vote::class, ['meeting' => $this->meeting])
        ->call('cancelVote')
        ->assertHasErrors('meeting')
        ->assertNoRedirect();
});
