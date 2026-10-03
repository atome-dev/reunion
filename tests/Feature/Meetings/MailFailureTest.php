<?php

use App\Actions\Meetings\ConfirmMeeting;
use App\Actions\Meetings\OpenVote;
use App\Enums\MeetingStatus;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->group = Group::factory()->create();
    $this->failing = User::factory()->create();
    $this->other = User::factory()->create();
    $this->group->addMember($this->failing);
    $this->group->addMember($this->other);
    $this->meeting = Meeting::factory()->for($this->group)->create();

    $this->sentTo = [];
    Event::listen(NotificationSending::class, function (NotificationSending $event) {
        if ($event->notifiable->is($this->failing)) {
            throw new RuntimeException('smtp down');
        }
    });
    Event::listen(NotificationSent::class, function (NotificationSent $event) {
        $this->sentTo[] = $event->notifiable->id;
    });
});

test('a failing mail does not block confirmation or the other members', function () {
    (new ConfirmMeeting)($this->meeting, Carbon::parse('2026-11-02 17:00', 'UTC'), Carbon::parse('2026-11-02 19:00', 'UTC'));

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Confirmed)
        ->and($this->sentTo)->toContain($this->other->id)
        ->and($this->sentTo)->not->toContain($this->failing->id);
});

test('a failing mail does not block opening a vote', function () {
    (new OpenVote)($this->meeting, [
        [Carbon::parse('2026-11-02 17:00', 'UTC'), Carbon::parse('2026-11-02 19:00', 'UTC')],
        [Carbon::parse('2026-11-03 17:00', 'UTC'), Carbon::parse('2026-11-03 19:00', 'UTC')],
    ]);

    expect($this->meeting->fresh()->status)->toBe(MeetingStatus::Voting)
        ->and($this->sentTo)->toContain($this->other->id);
});

test('a failing mail does not block the other reminders', function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(9, 0));
    $this->meeting->forceFill(['range_start' => '2026-11-02', 'range_end' => '2026-11-20', 'deadline' => '2026-11-03'])->save();

    $this->artisan('meetings:send-reminders')->assertSuccessful();

    expect($this->sentTo)->toContain($this->other->id)
        ->and($this->meeting->fresh()->reminder_sent_at)->not->toBeNull();
});
