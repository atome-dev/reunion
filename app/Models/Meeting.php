<?php

namespace App\Models;

use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $group_id
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property int $created_by
 */
#[Fillable(['title', 'description', 'location'])]
class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory;

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<MeetingSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(MeetingSlot::class)->orderBy('starts_at');
    }

    /**
     * Meetings with at least one date still ahead.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->whereHas('slots', fn (Builder $slots) => $slots->where('starts_at', '>=', now()));
    }

    /**
     * Meetings whose dates are all behind us.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function past(Builder $query): void
    {
        $query->whereDoesntHave('slots', fn (Builder $slots) => $slots->where('starts_at', '>=', now()));
    }

    public function isPast(): bool
    {
        return ! $this->slots()->where('starts_at', '>=', now())->exists();
    }
}
