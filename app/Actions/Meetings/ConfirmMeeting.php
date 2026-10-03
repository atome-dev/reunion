<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingStatus;
use App\Exceptions\InvalidMeetingTransition;
use App\Models\Meeting;
use App\Notifications\MeetingConfirmedNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ConfirmMeeting
{
    public function __invoke(Meeting $meeting, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        DB::transaction(function () use ($meeting, $startsAt, $endsAt): void {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);

            if ($locked->status === MeetingStatus::Confirmed) {
                throw new InvalidMeetingTransition(__('This meeting is already confirmed.'));
            }

            $locked->forceFill([
                'status' => MeetingStatus::Confirmed,
                'confirmed_starts_at' => $startsAt->copy()->utc(),
                'confirmed_ends_at' => $endsAt->copy()->utc(),
            ])->save();
        });

        $meeting->refresh();

        foreach ($meeting->group->members as $member) {
            rescue(fn () => $member->notify(new MeetingConfirmedNotification($meeting)), report: true);
        }
    }
}
