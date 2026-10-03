<?php

namespace App\Models;

use App\Enums\GroupRole;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property int $owner_id
 */
#[Fillable(['name'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<GroupInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(GroupInvitation::class);
    }

    /** @return HasMany<Meeting, $this> */
    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function isOrganizer(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function addMember(User $user, GroupRole $role = GroupRole::Member): void
    {
        $this->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
    }

    /**
     * Remove a member along with their votes on this group's meetings (their availability is personal and kept).
     */
    public function removeMember(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $meetingIds = $this->meetings()->pluck('id');

            SlotVote::query()
                ->where('user_id', $user->id)
                ->whereIn('meeting_slot_id', MeetingSlot::query()->select('id')->whereIn('meeting_id', $meetingIds))
                ->delete();

            $this->members()->detach($user->id);
        });
    }
}
