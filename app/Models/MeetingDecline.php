<?php

namespace App\Models;

use Database\Factories\MeetingDeclineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's statement that they will not come to a confirmed meeting.
 *
 * @property int $id
 * @property int $meeting_id
 * @property int $user_id
 */
class MeetingDecline extends Model
{
    /** @use HasFactory<MeetingDeclineFactory> */
    use HasFactory;

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
}
