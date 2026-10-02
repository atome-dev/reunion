<?php

use App\Actions\Meetings\FindBestSlot;
use Illuminate\Support\Carbon;

function tally(int $onSite, int $remote, string $startsAt): array
{
    return ['onSite' => $onSite, 'remote' => $remote, 'startsAt' => Carbon::parse($startsAt)];
}

test('the date with the most attendees wins', function () {
    expect((new FindBestSlot)(['a' => tally(2, 1, '2026-10-14'), 'b' => tally(3, 1, '2026-10-16')]))->toBe('b');
});

test('a tie is broken by the number of people on site', function () {
    expect((new FindBestSlot)(['a' => tally(1, 3, '2026-10-14'), 'b' => tally(3, 1, '2026-10-16')]))->toBe('b');
});

test('a complete tie is broken by the earliest date', function () {
    expect((new FindBestSlot)(['late' => tally(2, 1, '2026-10-20'), 'early' => tally(2, 1, '2026-10-14')]))->toBe('early');
});

test('there is no best date when nobody can come', function () {
    expect((new FindBestSlot)(['a' => tally(0, 0, '2026-10-14')]))->toBeNull()
        ->and((new FindBestSlot)([]))->toBeNull();
});
