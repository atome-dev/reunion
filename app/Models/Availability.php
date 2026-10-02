<?php

namespace App\Models;

use App\Enums\AvailabilityStatus;
use Database\Factories\AvailabilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $meeting_slot_id
 * @property int $user_id
 * @property AvailabilityStatus $status
 */
#[Fillable(['status'])]
class Availability extends Model
{
    /** @use HasFactory<AvailabilityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AvailabilityStatus::class,
        ];
    }

    /** @return BelongsTo<MeetingSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(MeetingSlot::class, 'meeting_slot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
