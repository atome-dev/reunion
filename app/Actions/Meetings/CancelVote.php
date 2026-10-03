<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingStatus;
use App\Exceptions\InvalidMeetingTransition;
use App\Models\Meeting;
use Illuminate\Support\Facades\DB;

class CancelVote
{
    public function __invoke(Meeting $meeting): void
    {
        DB::transaction(function () use ($meeting): void {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);

            if ($locked->status !== MeetingStatus::Voting) {
                throw new InvalidMeetingTransition(__('There is no vote to cancel.'));
            }

            $locked->slots()->delete();
            $locked->forceFill(['status' => MeetingStatus::Collecting])->save();
        });

        $meeting->refresh();
    }
}
