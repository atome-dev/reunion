<?php

namespace App\Actions\Meetings;

use Carbon\CarbonInterface;

/**
 * Pick the best meeting date: most attendees, then most on site, then the earliest.
 */
class FindBestSlot
{
    /**
     * @param  iterable<int|string, array{onSite: int, remote: int, startsAt: CarbonInterface}>  $tallies
     */
    public function __invoke(iterable $tallies): int|string|null
    {
        $bestKey = null;
        $best = null;

        foreach ($tallies as $key => $tally) {
            $total = $tally['onSite'] + $tally['remote'];

            if ($total === 0) {
                continue;
            }

            if ($best === null || $this->beats($total, $tally, $best)) {
                $bestKey = $key;
                $best = ['total' => $total] + $tally;
            }
        }

        return $bestKey;
    }

    /**
     * @param  array{onSite: int, remote: int, startsAt: CarbonInterface}  $tally
     * @param  array{total: int, onSite: int, remote: int, startsAt: CarbonInterface}  $best
     */
    private function beats(int $total, array $tally, array $best): bool
    {
        if ($total !== $best['total']) {
            return $total > $best['total'];
        }

        if ($tally['onSite'] !== $best['onSite']) {
            return $tally['onSite'] > $best['onSite'];
        }

        return $tally['startsAt']->lt($best['startsAt']);
    }
}
