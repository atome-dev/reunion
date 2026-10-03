<?php

use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\AvailabilityReminderNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(now('Europe/Paris')->setDate(2026, 11, 2)->setTime(9, 0));
    $this->group = Group::factory()->create();
    $this->answered = User::factory()->create();
    $this->silent = User::factory()->create();
    $this->group->addMember($this->answered);
    $this->group->addMember($this->silent);
});

test('members who have not answered are reminded the day before the deadline, once', function () {
    $meeting = Meeting::factory()->for($this->group)->create(['range_start' => '2026-11-02', 'range_end' => '2026-11-20', 'deadline' => '2026-11-03']);
    AvailabilityDay::factory()->for($meeting)->for($this->answered)->cells(str_repeat('p', 28))->create(['day' => '2026-11-05']);

    $this->artisan('meetings:send-reminders')->assertSuccessful();
    $this->artisan('meetings:send-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->silent, AvailabilityReminderNotification::class, 1);
    Notification::assertNotSentTo($this->answered, AvailabilityReminderNotification::class);
    expect($meeting->fresh()->reminder_sent_at)->not->toBeNull();
});

test('no reminder for other deadlines or for votes and confirmed meetings', function () {
    Meeting::factory()->for($this->group)->create(['range_start' => '2026-11-02', 'range_end' => '2026-11-20', 'deadline' => '2026-11-04']);
    Meeting::factory()->for($this->group)->voting()->create(['range_start' => '2026-11-02', 'range_end' => '2026-11-20', 'deadline' => '2026-11-03']);

    $this->artisan('meetings:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

test('the reminder runs every morning at 9 in Paris', function () {
    $this->artisan('schedule:list')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'meetings:send-reminders'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *')
        ->and($event->timezone)->toBe('Europe/Paris');
});
