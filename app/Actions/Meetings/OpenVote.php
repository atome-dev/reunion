<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingStatus;
use App\Exceptions\InvalidMeetingTransition;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\VoteOpenedNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class OpenVote
{
    /**
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $slots  start and end of each slot, in UTC
     */
    public function __invoke(Meeting $meeting, array $slots, User $openedBy): void
    {
        $distinct = collect($slots)->unique(fn (array $slot): string => $slot[0]->getTimestamp().'-'.$slot[1]->getTimestamp());

        DB::transaction(function () use ($meeting, $distinct): void {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);

            if ($locked->status !== MeetingStatus::Collecting || $distinct->count() < 2) {
                throw new InvalidMeetingTransition(__('Choose at least two different slots to open a vote.'));
            }

            $locked->slots()->delete();

            foreach ($distinct as [$startsAt, $endsAt]) {
                $locked->slots()->create(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
            }

            $locked->forceFill(['status' => MeetingStatus::Voting])->save();
        });

        $meeting->refresh()->load('slots');

        foreach ($meeting->group->members()->whereKeyNot($openedBy->id)->get() as $member) {
            rescue(fn () => $member->notify(new VoteOpenedNotification($meeting)), report: true);
        }
    }
}
