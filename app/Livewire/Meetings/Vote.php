<?php

namespace App\Livewire\Meetings;

use App\Actions\Meetings\CancelVote;
use App\Actions\Meetings\ConfirmMeeting;
use App\Actions\Meetings\FindBestSlot;
use App\Enums\AvailabilityStatus;
use App\Exceptions\InvalidMeetingTransition;
use App\Models\AvailabilityDay;
use App\Models\Meeting;
use App\Models\MeetingSlot;
use App\Models\SlotVote;
use App\Models\User;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Vote extends Component
{
    public Meeting $meeting;

    /** @var array<int, string> */
    public array $responses = [];

    public function mount(Meeting $meeting): void
    {
        $this->authorize('view', $meeting);

        $this->meeting = $meeting;
        $grid = $meeting->availabilityDays()->where('user_id', Auth::id())->pluck('cells', 'day');

        foreach ($this->proposedSlots as $slot) {
            $this->responses[$slot->id] = $slot->votes->firstWhere('user_id', Auth::id())?->status->value
                ?? $this->fromGrid($slot, $grid->all())->value;
        }
    }

    public function hydrate(): void
    {
        $this->authorize('view', $this->meeting);
    }

    public function save(): void
    {
        $this->authorize('vote', $this->meeting);

        $this->validate($this->proposedSlots->mapWithKeys(fn (MeetingSlot $slot): array => [
            "responses.{$slot->id}" => ['required', Rule::enum(AvailabilityStatus::class)],
        ])->all());

        foreach ($this->proposedSlots as $slot) {
            $vote = $slot->votes->firstWhere('user_id', Auth::id()) ?? tap(new SlotVote, function (SlotVote $vote) use ($slot): void {
                $vote->slot()->associate($slot);
                $vote->user()->associate(Auth::user());
            });

            $vote->status = AvailabilityStatus::from($this->responses[$slot->id]);
            $vote->save();
        }

        unset($this->proposedSlots, $this->tallies, $this->bestSlotId, $this->nonVoters);

        Flux::toast(variant: 'success', text: __('Your vote has been saved.'));
    }

    public function confirmSlot(int $slotId, ConfirmMeeting $confirmMeeting): void
    {
        $this->authorize('manage', $this->meeting);

        $slot = $this->meeting->slots()->findOrFail($slotId);

        $this->applyTransition(fn () => $confirmMeeting($this->meeting, $slot->starts_at, $slot->ends_at));
    }

    public function cancelVote(CancelVote $cancelVote): void
    {
        $this->authorize('manage', $this->meeting);

        $this->applyTransition(fn () => $cancelVote($this->meeting));
    }

    #[Computed]
    public function isOrganizer(): bool
    {
        return $this->meeting->group->isOrganizer(Auth::user());
    }

    /**
     * @return Collection<int, MeetingSlot>
     */
    #[Computed]
    public function proposedSlots(): Collection
    {
        return $this->meeting->slots()->with('votes')->orderBy('starts_at')->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->meeting->group->members()->orderBy('name')->get();
    }

    /**
     * @return array<int, array{onSite: int, remote: int, startsAt: CarbonInterface}>
     */
    #[Computed]
    public function tallies(): array
    {
        return $this->proposedSlots->mapWithKeys(fn (MeetingSlot $slot): array => [$slot->id => [
            'onSite' => $slot->votes->where('status', AvailabilityStatus::OnSite)->count(),
            'remote' => $slot->votes->where('status', AvailabilityStatus::Remote)->count(),
            'startsAt' => $slot->starts_at,
        ]])->all();
    }

    #[Computed]
    public function bestSlotId(): ?int
    {
        return (new FindBestSlot)($this->tallies);
    }

    /**
     * Members who have not voted for every slot.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function nonVoters(): Collection
    {
        return $this->members->reject(fn (User $member): bool => $this->proposedSlots->every(
            fn (MeetingSlot $slot): bool => $slot->votes->contains('user_id', $member->id)
        ))->values();
    }

    /**
     * @param  array<string, string>  $grid
     */
    private function fromGrid(MeetingSlot $slot, array $grid): AvailabilityStatus
    {
        $start = $slot->startsAtLocal();
        $firstCell = ($start->hour - AvailabilityDay::FirstHour) * 2 + intdiv($start->minute, 30);
        $length = intdiv((int) $slot->starts_at->diffInMinutes($slot->ends_at), 30);

        return AvailabilityDay::statusFor($grid[$start->toDateString()] ?? AvailabilityDay::Empty, $firstCell, $length);
    }

    private function applyTransition(callable $action): void
    {
        try {
            $action();
        } catch (InvalidMeetingTransition $exception) {
            throw ValidationException::withMessages(['meeting' => $exception->getMessage()]);
        }

        $this->redirectRoute('meetings.show', $this->meeting, navigate: true);
    }
}
