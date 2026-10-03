<?php

namespace App\Actions\Meetings;

use App\Enums\AvailabilityStatus;
use App\Models\AvailabilityDay;

/**
 * Find the best meeting windows from members' availability grids.
 *
 * A member counts as present if available for the whole duration, and on site only if on site for the
 * whole duration. Consecutive starts of a day with exactly the same people are grouped into one window.
 */
class FindBestWindows
{
    /**
     * @param  array<int, array<string, string>>  $cellsByMember  member id => [day => 28 cells]
     * @param  list<string>  $days
     * @return list<array{day: string, firstStart: int, lastStart: int, length: int, onSite: list<int>, remote: list<int>}>
     */
    public function __invoke(array $cellsByMember, array $days, int $durationMinutes, int $minParticipants, int $minOnSite, int $limit = 15): array
    {
        $length = intdiv($durationMinutes, 30);
        $windows = [];

        foreach ($days as $day) {
            $current = null;

            for ($start = 0; $start + $length <= AvailabilityDay::CellCount; $start++) {
                [$onSite, $remote] = $this->attendees($cellsByMember, $day, $start, $length);

                if (count($onSite) + count($remote) < max(1, $minParticipants) || count($onSite) < $minOnSite) {
                    $current = $this->flush($windows, $current);

                    continue;
                }

                if ($current !== null && $current['onSite'] === $onSite && $current['remote'] === $remote && $current['lastStart'] === $start - 1) {
                    $current['lastStart'] = $start;

                    continue;
                }

                $this->flush($windows, $current);
                $current = ['day' => $day, 'firstStart' => $start, 'lastStart' => $start, 'length' => $length, 'onSite' => $onSite, 'remote' => $remote];
            }

            $this->flush($windows, $current);
        }

        usort($windows, fn (array $a, array $b): int => [count($b['onSite']) + count($b['remote']), count($b['onSite']), $a['day'], $a['firstStart']]
            <=> [count($a['onSite']) + count($a['remote']), count($a['onSite']), $b['day'], $b['firstStart']]);

        return array_slice($windows, 0, $limit);
    }

    /**
     * @param  array<int, array<string, string>>  $cellsByMember
     * @return array{0: list<int>, 1: list<int>}
     */
    private function attendees(array $cellsByMember, string $day, int $start, int $length): array
    {
        $onSite = [];
        $remote = [];

        foreach ($cellsByMember as $memberId => $days) {
            match (AvailabilityDay::statusFor($days[$day] ?? AvailabilityDay::Empty, $start, $length)) {
                AvailabilityStatus::OnSite => $onSite[] = $memberId,
                AvailabilityStatus::Remote => $remote[] = $memberId,
                AvailabilityStatus::Unavailable => null,
            };
        }

        return [$onSite, $remote];
    }

    /**
     * @param  list<array<string, mixed>>  $windows
     * @param  array<string, mixed>|null  $current
     */
    private function flush(array &$windows, ?array $current): null
    {
        if ($current !== null) {
            $windows[] = $current;
        }

        return null;
    }
}
