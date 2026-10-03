<?php

namespace App\Livewire;

use App\Actions\Groups\CreateGroup;
use App\Enums\MeetingStatus;
use App\Models\AvailabilityDay;
use App\Models\Group;
use App\Models\Meeting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    #[Validate('required|string|max:120')]
    public string $name = '';

    public function createGroup(CreateGroup $createGroup): void
    {
        $this->validate();

        $group = $createGroup(Auth::user(), $this->name);

        $this->redirectRoute('groups.show', $group, navigate: true);
    }

    /**
     * @return Collection<int, Group>
     */
    #[Computed]
    public function groups(): Collection
    {
        return Auth::user()->groups()
            ->withCount('members')
            ->with(['meetings' => fn ($query) => $query->upcoming()])
            ->get();
    }

    /**
     * Requests of my groups waiting for my grid, and votes I have not completed.
     *
     * @return Collection<int, Meeting>
     */
    #[Computed]
    public function pendingMeetings(): Collection
    {
        $userId = Auth::id();

        return Meeting::query()
            ->whereHas('group.members', fn (Builder $members) => $members->whereKey($userId))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $collecting) => $collecting
                    ->where('status', MeetingStatus::Collecting->value)
                    ->whereNotExists(fn (QueryBuilder $days) => $days
                        ->selectRaw('1')
                        ->from('availability_days')
                        ->where('availability_days.user_id', $userId)
                        ->where('availability_days.cells', '!=', AvailabilityDay::Empty)
                        ->whereRaw('availability_days.day >= date(meetings.range_start)')
                        ->whereRaw('availability_days.day <= date(meetings.range_end)')))
                ->orWhere(fn (Builder $voting) => $voting
                    ->where('status', MeetingStatus::Voting->value)
                    ->whereHas('slots', fn (Builder $slots) => $slots->whereDoesntHave('votes', fn (Builder $votes) => $votes->where('user_id', $userId)))))
            ->with('group')
            ->orderBy('deadline')
            ->get();
    }

    /**
     * Days I filled in between today and the last editable day.
     */
    #[Computed]
    public function filledDayCount(): int
    {
        return AvailabilityDay::query()
            ->where('user_id', Auth::id())
            ->whereBetween('day', [now(config('app.display_timezone'))->toDateString(), AvailabilityDay::lastEditableDay()->toDateString()])
            ->count();
    }

    /**
     * Confirmed meetings of my groups still ahead.
     *
     * @return Collection<int, Meeting>
     */
    #[Computed]
    public function confirmedMeetings(): Collection
    {
        return Meeting::query()
            ->whereHas('group.members', fn (Builder $members) => $members->whereKey(Auth::id()))
            ->where('status', MeetingStatus::Confirmed->value)
            ->where('confirmed_starts_at', '>=', now())
            ->with('group')
            ->orderBy('confirmed_starts_at')
            ->get();
    }
}
