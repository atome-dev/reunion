<?php

namespace App\Actions\Availability;

use App\Enums\MeetingStatus;
use App\Models\AvailabilityDay;
use App\Models\Meeting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Grid cells taken by confirmed meetings of the users' current groups, computed on the fly.
 */
class BusyCells
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, array<int, array{meetingId: int, title: string, group: string}>>>
     */
    public function __invoke(array $userIds, string $from, string $to, ?int $exceptMeetingId = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $timezone = config('app.display_timezone');

        $meetings = Meeting::query()
            ->where('status', MeetingStatus::Confirmed->value)
            ->whereNotNull('confirmed_starts_at')
            ->whereNotNull('confirmed_ends_at')
            ->when($exceptMeetingId !== null, fn (Builder $query) => $query->whereKeyNot($exceptMeetingId))
            ->whereBetween('confirmed_starts_at', [
                CarbonImmutable::parse($from, $timezone)->startOfDay()->utc(),
                CarbonImmutable::parse($to, $timezone)->endOfDay()->utc(),
            ])
            ->whereHas('group.members', fn (Builder $members) => $members->whereIn('users.id', $userIds))
            ->with(['group:id,name', 'group.members:users.id'])
            ->orderBy('confirmed_starts_at')
            ->get();

        $busy = [];

        foreach ($meetings as $meeting) {
            $start = CarbonImmutable::instance($meeting->confirmed_starts_at)->setTimezone($timezone);
            $end = CarbonImmutable::instance($meeting->confirmed_ends_at)->setTimezone($timezone);
            $day = $start->toDateString();
            $first = max(0, ($start->hour - AvailabilityDay::FirstHour) * 2 + intdiv($start->minute, 30));
            $endMinutes = $end->toDateString() === $day
                ? ($end->hour - AvailabilityDay::FirstHour) * 60 + $end->minute
                : AvailabilityDay::CellCount * 30;
            $last = min(AvailabilityDay::CellCount, (int) ceil($endMinutes / 30));
            $info = ['meetingId' => $meeting->id, 'title' => $meeting->title, 'group' => $meeting->group->name];

            foreach ($meeting->group->members as $member) {
                if (! in_array($member->id, $userIds, true)) {
                    continue;
                }

                for ($cell = $first; $cell < $last; $cell++) {
                    $busy[$member->id][$day][$cell] ??= $info;
                }
            }
        }

        return $busy;
    }
}
