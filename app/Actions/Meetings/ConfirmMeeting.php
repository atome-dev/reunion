<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingStatus;
use App\Exceptions\InvalidMeetingTransition;
use App\Models\Meeting;
use App\Notifications\MeetingConfirmedNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

class ConfirmMeeting
{
    public function __invoke(Meeting $meeting, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        if ($meeting->status === MeetingStatus::Confirmed) {
            throw new InvalidMeetingTransition(__('This meeting is already confirmed.'));
        }

        $meeting->forceFill([
            'status' => MeetingStatus::Confirmed,
            'confirmed_starts_at' => $startsAt->copy()->utc(),
            'confirmed_ends_at' => $endsAt->copy()->utc(),
        ])->save();

        Notification::send($meeting->group->members, new MeetingConfirmedNotification($meeting));
    }
}
