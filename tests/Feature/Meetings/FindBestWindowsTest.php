<?php

use App\Actions\Meetings\FindBestWindows;

/**
 * Build a 28-cell day: $ranges maps "start-end" (cells, end exclusive) to "p" or "d".
 *
 * @param  array<string, string>  $ranges
 */
function day(array $ranges = []): string
{
    $cells = str_repeat('0', 28);

    foreach ($ranges as $range => $value) {
        [$start, $end] = array_map('intval', explode('-', $range));
        $cells = substr_replace($cells, str_repeat($value, $end - $start), $start, $end - $start);
    }

    return $cells;
}

function findWindows(array $cells, array $days, int $duration = 120, int $minParticipants = 1, int $minOnSite = 0, int $limit = 15): array
{
    return (new FindBestWindows)($cells, $days, $duration, $minParticipants, $minOnSite, $limit);
}

test('a member is present only if available for the whole duration', function () {
    $windows = findWindows([1 => ['2026-11-02' => day(['20-24' => 'p'])]], ['2026-11-02'], 120);

    expect($windows)->toHaveCount(1)
        ->and($windows[0])->toMatchArray(['day' => '2026-11-02', 'firstStart' => 20, 'lastStart' => 20, 'length' => 4, 'onSite' => [1], 'remote' => []]);
});

test('a member mixing on site and remote counts as remote', function () {
    $windows = findWindows([1 => ['2026-11-02' => day(['20-22' => 'p', '22-24' => 'd'])]], ['2026-11-02'], 120);

    expect($windows[0]['onSite'])->toBe([])->and($windows[0]['remote'])->toBe([1]);
});

test('consecutive starts with the same people are grouped, a change splits them', function () {
    $cells = [
        1 => ['2026-11-02' => day(['18-26' => 'p'])],
        2 => ['2026-11-02' => day(['20-26' => 'd'])],
    ];

    $windows = findWindows($cells, ['2026-11-02'], 120);

    expect($windows[0])->toMatchArray(['firstStart' => 20, 'lastStart' => 22, 'onSite' => [1], 'remote' => [2]])
        ->and($windows[1])->toMatchArray(['firstStart' => 18, 'lastStart' => 19, 'onSite' => [1], 'remote' => []]);
});

test('windows never run past 22:00', function () {
    $windows = findWindows([1 => ['2026-11-02' => day(['24-28' => 'p'])]], ['2026-11-02'], 120);

    expect($windows)->toHaveCount(1)->and($windows[0]['lastStart'])->toBe(24);
    expect(findWindows([1 => ['2026-11-02' => day(['24-28' => 'p'])]], ['2026-11-02'], 150))->toBe([]);
});

test('minimums filter windows', function () {
    $cells = [
        1 => ['2026-11-02' => day(['20-24' => 'p'])],
        2 => ['2026-11-02' => day(['20-24' => 'd'])],
        3 => ['2026-11-03' => day(['20-24' => 'p'])],
    ];

    expect(findWindows($cells, ['2026-11-02', '2026-11-03'], 120, 2))->toHaveCount(1)
        ->and(findWindows($cells, ['2026-11-02', '2026-11-03'], 120, 1, 2))->toBe([]);
});

test('windows are ranked by attendees, then on site, then earliest', function () {
    $cells = [
        1 => ['2026-11-02' => day(['10-14' => 'd']), '2026-11-03' => day(['10-14' => 'p']), '2026-11-04' => day(['10-14' => 'p'])],
        2 => ['2026-11-04' => day(['10-14' => 'd'])],
    ];

    $windows = findWindows($cells, ['2026-11-02', '2026-11-03', '2026-11-04'], 120);

    expect(array_column($windows, 'day'))->toBe(['2026-11-04', '2026-11-03', '2026-11-02']);
});

test('only days of the range are considered and the result is limited', function () {
    $cells = [1 => ['2026-11-01' => day(['0-28' => 'p']), '2026-11-02' => day(['0-4' => 'p', '6-10' => 'p'])]];

    expect(array_column(findWindows($cells, ['2026-11-02'], 60), 'day'))->toBe(['2026-11-02', '2026-11-02'])
        ->and(findWindows($cells, ['2026-11-02'], 60, limit: 1))->toHaveCount(1);
});

test('nobody available gives no window', function () {
    expect(findWindows([], ['2026-11-02']))->toBe([]);
});
