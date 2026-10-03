<?php

namespace App\Console\Commands;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Notifications\AvailabilityReminderNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('meetings:send-reminders')]
#[Description('Remind members who have not given their availability, the day before the deadline')]
class SendAvailabilityReminders extends Command
{
    public function handle(): int
    {
        $tomorrow = now(config('app.display_timezone'))->addDay()->toDateString();

        Meeting::query()
            ->where('status', MeetingStatus::Collecting->value)
            ->whereNull('reminder_sent_at')
            ->whereDate('deadline', $tomorrow)
            ->with('group.members')
            ->each(function (Meeting $meeting): void {
                $respondentIds = $meeting->respondentIds();

                Notification::send(
                    $meeting->group->members->reject(fn ($member) => $respondentIds->contains($member->id)),
                    new AvailabilityReminderNotification($meeting),
                );

                $meeting->forceFill(['reminder_sent_at' => now()])->save();
            });

        return self::SUCCESS;
    }
}
