<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MeetingSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $meeting_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 */
#[Fillable(['starts_at', 'ends_at'])]
class MeetingSlot extends Model
{
    /** @use HasFactory<MeetingSlotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return HasMany<SlotVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(SlotVote::class);
    }

    public function startsAtLocal(): CarbonInterface
    {
        return $this->starts_at->copy()->setTimezone(config('app.display_timezone'));
    }

    public function endsAtLocal(): CarbonInterface
    {
        return $this->ends_at->copy()->setTimezone(config('app.display_timezone'));
    }
}
