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
 */
#[Fillable(['starts_at'])]
class MeetingSlot extends Model
{
    /** @use HasFactory<MeetingSlotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return HasMany<Availability, $this> */
    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class);
    }

    public function startsAtLocal(): CarbonInterface
    {
        return $this->starts_at->copy()->setTimezone(config('app.display_timezone'));
    }
}
