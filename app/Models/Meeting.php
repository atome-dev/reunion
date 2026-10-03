<?php

namespace App\Models;

use App\Enums\MeetingStatus;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A meeting request: members fill in their availabilities over a date range, then a date is confirmed
 * directly or after a vote.
 *
 * @property int $id
 * @property int $group_id
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property int $created_by
 * @property Carbon $range_start
 * @property Carbon $range_end
 * @property Carbon $deadline
 * @property MeetingStatus $status
 * @property Carbon|null $confirmed_starts_at
 * @property Carbon|null $confirmed_ends_at
 * @property Carbon|null $reminder_sent_at
 */
#[Fillable(['title', 'description', 'location', 'range_start', 'range_end', 'deadline'])]
class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'collecting',
    ];

    protected function casts(): array
    {
        return [
            'range_start' => 'date',
            'range_end' => 'date',
            'deadline' => 'date',
            'status' => MeetingStatus::class,
            'confirmed_starts_at' => 'datetime',
            'confirmed_ends_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

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

    /** @return HasMany<AvailabilityDay, $this> */
    public function availabilityDays(): HasMany
    {
        return $this->hasMany(AvailabilityDay::class);
    }

    /**
     * Requests still in progress, or confirmed for a date ahead.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('status', '!=', MeetingStatus::Confirmed->value)
            ->orWhere('confirmed_starts_at', '>=', now()));
    }

    /**
     * Confirmed meetings that already took place.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function past(Builder $query): void
    {
        $query->where('status', MeetingStatus::Confirmed->value)->where('confirmed_starts_at', '<', now());
    }

    /**
     * Every day of the range, as "Y-m-d" strings.
     *
     * @return list<string>
     */
    public function rangeDays(): array
    {
        return collect(CarbonPeriod::create($this->range_start->toDateString(), $this->range_end->toDateString()))
            ->map(fn (CarbonInterface $day): string => $day->toDateString())
            ->values()
            ->all();
    }

    /**
     * Cells of the group's current members, keyed by member id then day.
     *
     * @return array<int, array<string, string>>
     */
    public function cellsByMember(): array
    {
        $memberIds = $this->group->members()->pluck('users.id');

        return $this->availabilityDays()
            ->whereIn('user_id', $memberIds)
            ->orderBy('day')
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $days): array => $days->mapWithKeys(fn (AvailabilityDay $day): array => [$day->day => $day->cells])->all())
            ->all();
    }

    /**
     * Current members who filled at least one day.
     *
     * @return Collection<int, int>
     */
    public function respondentIds(): Collection
    {
        return collect(array_keys($this->cellsByMember()));
    }

    public function isPast(): bool
    {
        return $this->status === MeetingStatus::Confirmed && $this->confirmed_starts_at?->isPast();
    }
}
