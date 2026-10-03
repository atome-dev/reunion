<?php

namespace App\Models;

use App\Enums\AvailabilityStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AvailabilityDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's availability for one day of a meeting request: 28 half-hour cells from 8:00 to 22:00,
 * each "0" (unavailable), "p" (on site) or "d" (remote).
 *
 * @property int $id
 * @property int $meeting_id
 * @property int $user_id
 * @property string $day
 * @property string $cells
 */
#[Fillable(['cells'])]
class AvailabilityDay extends Model
{
    /** @use HasFactory<AvailabilityDayFactory> */
    use HasFactory;

    public const int CellCount = 28;

    public const int FirstHour = 8;

    public const string Empty = '0000000000000000000000000000';

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Local time at which a cell starts ("08:00" for 0, "22:00" for 28 — the end of the last cell).
     */
    public static function cellTime(int $cell): string
    {
        return sprintf('%02d:%02d', self::FirstHour + intdiv($cell, 2), ($cell % 2) * 30);
    }

    /**
     * The local date and time (display timezone) at which a cell starts on a given day.
     */
    public static function localDateTime(string $day, int $cell): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $day.' '.self::cellTime($cell), config('app.display_timezone'));
    }

    /**
     * How a member can attend a stretch of cells: on site only if every cell is on site.
     */
    public static function statusFor(string $cells, int $start, int $length): AvailabilityStatus
    {
        $stretch = substr($cells, $start, $length);

        if (strlen($stretch) < $length || str_contains($stretch, '0')) {
            return AvailabilityStatus::Unavailable;
        }

        return $stretch === str_repeat('p', $length) ? AvailabilityStatus::OnSite : AvailabilityStatus::Remote;
    }
}
