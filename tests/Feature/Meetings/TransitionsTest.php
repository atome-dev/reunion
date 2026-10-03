<?php

use App\Actions\Meetings\BuildIcsCalendar;
use App\Actions\Meetings\CancelVote;
use App\Actions\Meetings\ConfirmMeeting;
use App\Actions\Meetings\OpenVote;
use App\Enums\MeetingStatus;
use App\Exceptions\InvalidMeetingTransition;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\SlotVote;
use App\Models\User;
use App\Notifications\MeetingConfirmedNotification;
use App\Notifications\VoteOpenedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->group = Group::factory()->create();
    $this->member = User::factory()->create();
    $this->group->addMember($this->member);
    $this->meeting = Meeting::factory()->for($this->group)->create(['title' => 'AG; rentrée', 'location' => 'Salle, du fond']);
});

function utc(string $time): Carbon
{
    return Carbon::parse($time, 'UTC');
}

test('opening a vote creates the slots and notifies members except the creator', function () {
    (new OpenVote)($this->meeting, [
        [utc('2026-11-02 17:00'), utc('2026-11-02 19:00')],
        [utc('2026-11-03 17:00'), utc('2026-11-03 19:00')],
    ]);

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Voting)
        ->and($this->meeting->slots()->count())->toBe(2);
    Notification::assertSentTo($this->member, VoteOpenedNotification::class);
    Notification::assertNotSentTo($this->group->owner, VoteOpenedNotification::class);
});

test('a vote needs two distinct slots and an open collection', function () {
    expect(fn () => (new OpenVote)($this->meeting, [
        [utc('2026-11-02 17:00'), utc('2026-11-02 19:00')],
        [utc('2026-11-02 17:00'), utc('2026-11-02 19:00')],
    ]))->toThrow(InvalidMeetingTransition::class);

    $voting = Meeting::factory()->for($this->group)->voting()->create();

    expect(fn () => (new OpenVote)($voting, [
        [utc('2026-11-02 17:00'), utc('2026-11-02 19:00')],
        [utc('2026-11-03 17:00'), utc('2026-11-03 19:00')],
    ]))->toThrow(InvalidMeetingTransition::class);
});

test('confirming stores the date and emails everyone with a calendar file', function () {
    (new ConfirmMeeting)($this->meeting, utc('2026-11-02 17:00'), utc('2026-11-02 19:00'));

    $meeting = $this->meeting->fresh();
    expect($meeting->status)->toBe(MeetingStatus::Confirmed)
        ->and($meeting->confirmed_starts_at->format('Y-m-d H:i'))->toBe('2026-11-02 17:00');
    Notification::assertSentTo([$this->member, $this->group->owner], MeetingConfirmedNotification::class);

    expect(fn () => (new ConfirmMeeting)($meeting, utc('2026-11-03 17:00'), utc('2026-11-03 19:00')))
        ->toThrow(InvalidMeetingTransition::class);
});

test('the confirmation email carries an ics attachment', function () {
    (new ConfirmMeeting)($this->meeting, utc('2026-11-02 17:00'), utc('2026-11-02 19:00'));

    Notification::assertSentTo($this->member, MeetingConfirmedNotification::class, function (MeetingConfirmedNotification $notification) {
        $mail = $notification->toMail($this->member);

        return count($mail->rawAttachments) === 1
            && str_contains($mail->rawAttachments[0]['data'], 'DTSTART:20261102T170000Z');
    });
});

test('cancelling a vote deletes slots and votes and reopens the collection', function () {
    $voting = Meeting::factory()->for($this->group)->voting()->create();
    $slot = MeetingSlot::factory()->for($voting)->create();
    SlotVote::factory()->for($slot, 'slot')->for($this->member)->create();

    (new CancelVote)($voting);

    expect($voting->fresh()->status)->toBe(MeetingStatus::Collecting)
        ->and(MeetingSlot::count())->toBe(0)
        ->and(SlotVote::count())->toBe(0);
    expect(fn () => (new CancelVote)($this->meeting))->toThrow(InvalidMeetingTransition::class);
});

test('the calendar file is valid and escaped', function () {
    $this->meeting->forceFill([
        'status' => MeetingStatus::Confirmed,
        'confirmed_starts_at' => utc('2026-11-02 17:00'),
        'confirmed_ends_at' => utc('2026-11-02 19:00'),
    ])->save();

    $ics = (new BuildIcsCalendar)($this->meeting);

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->toContain("DTSTART:20261102T170000Z\r\n")
        ->toContain("DTEND:20261102T190000Z\r\n")
        ->toContain('SUMMARY:AG\; rentrée')
        ->toContain('LOCATION:Salle\, du fond')
        ->toContain('UID:meeting-'.$this->meeting->id.'@')
        ->toEndWith("END:VCALENDAR\r\n");
});
