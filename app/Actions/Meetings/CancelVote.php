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
        if ($meeting->status !== MeetingStatus::Voting) {
            throw new InvalidMeetingTransition(__('There is no vote to cancel.'));
        }

        DB::transaction(function () use ($meeting): void {
            $meeting->slots()->delete();
            $meeting->forceFill(['status' => MeetingStatus::Collecting])->save();
        });
    }
}
